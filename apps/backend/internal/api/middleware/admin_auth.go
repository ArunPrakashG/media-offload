package middleware

import (
	"net/http"
	"strings"

	"github.com/arunprakashg/nimbus-backend/internal/apiutil"
)

// AdminAuth returns a middleware that validates a static Bearer token on every
// inbound request. If ADMIN_API_SECRET is empty the route is disabled.
func AdminAuth(secret string) func(http.Handler) http.Handler {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			if secret == "" {
				apiutil.Error(w, http.StatusServiceUnavailable, "not_configured",
					"admin API is not configured on this server")
				return
			}

			auth := r.Header.Get("Authorization")
			token, ok := strings.CutPrefix(auth, "Bearer ")
			if !ok || token != secret {
				apiutil.Error(w, http.StatusUnauthorized, "unauthorized",
					"invalid or missing admin credentials")
				return
			}

			next.ServeHTTP(w, r)
		})
	}
}
