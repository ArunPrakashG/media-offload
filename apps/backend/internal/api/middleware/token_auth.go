package middleware

import (
	"context"
	"crypto/ed25519"
	"net/http"
	"strings"

	"github.com/arunprakashg/nimbus-backend/internal/apiutil"
	"github.com/arunprakashg/nimbus-backend/internal/license"
)

// claimsKey is the unexported context key used to store TokenClaims.
// Using a typed key prevents accidental collisions with other packages.
type claimsKey struct{}

// RequireActivationToken is a chi middleware that authenticates plugin requests
// to post-activation endpoints (deactivate, status, activations list).
//
// The plugin sends the Ed25519-signed activation token it received during
// activation as a Bearer token:
//
//	Authorization: Bearer <base64url(claims)>.<base64url(sig)>
//
// On success the verified *license.TokenClaims are stored in the request
// context and are retrieved by downstream handlers via ClaimsFromContext.
func RequireActivationToken(publicKey ed25519.PublicKey) func(http.Handler) http.Handler {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			auth := r.Header.Get("Authorization")
			token, ok := strings.CutPrefix(auth, "Bearer ")
			if !ok || strings.TrimSpace(token) == "" {
				apiutil.Error(w, http.StatusUnauthorized, "unauthorized",
					"a valid activation token is required in the Authorization header")
				return
			}

			claims, err := license.VerifyToken(strings.TrimSpace(token), publicKey)
			if err != nil {
				// Deliberately vague — don't reveal whether the token is expired vs tampered.
				apiutil.Error(w, http.StatusUnauthorized, "invalid_token",
					"activation token is invalid or expired; please re-activate your license")
				return
			}

			if claims.LicenseID == "" {
				// Token predates the LicenseID claim — issued by an older server version.
				apiutil.Error(w, http.StatusUnauthorized, "token_upgrade_required",
					"this token was issued by an older server; please re-activate your license to get a new token")
				return
			}

			ctx := context.WithValue(r.Context(), claimsKey{}, claims)
			next.ServeHTTP(w, r.WithContext(ctx))
		})
	}
}

// ClaimsFromContext returns the TokenClaims stored by RequireActivationToken,
// or nil if the middleware was not applied to this request.
func ClaimsFromContext(ctx context.Context) *license.TokenClaims {
	c, _ := ctx.Value(claimsKey{}).(*license.TokenClaims)
	return c
}
