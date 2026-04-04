package license

import (
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"strings"
	"time"
)

// TokenClaims is the payload embedded in a signed license token.
// The token is stored by the WordPress plugin and verified locally on every boot.
type TokenClaims struct {
	Domain    string   `json:"domain"`
	Product   string   `json:"product"`
	Tier      string   `json:"tier"`
	Features  []string `json:"features"`
	IssuedAt  int64    `json:"iat"`
	ExpiresAt int64    `json:"exp"`
}

// SignToken creates a token of the form base64url(json) + "." + base64url(ed25519sig).
// The private key never leaves the server; the plugin only holds the public key.
func SignToken(claims TokenClaims, privateKey ed25519.PrivateKey) (string, error) {
	payload, err := json.Marshal(claims)
	if err != nil {
		return "", fmt.Errorf("marshal claims: %w", err)
	}
	sig := ed25519.Sign(privateKey, payload)
	return base64.RawURLEncoding.EncodeToString(payload) + "." +
		base64.RawURLEncoding.EncodeToString(sig), nil
}

// VerifyToken verifies the Ed25519 signature and checks expiry.
// Used by the plugin (which embeds the public key) to validate tokens offline.
func VerifyToken(token string, publicKey ed25519.PublicKey) (*TokenClaims, error) {
	parts := strings.SplitN(token, ".", 2)
	if len(parts) != 2 {
		return nil, fmt.Errorf("malformed token")
	}
	payload, err := base64.RawURLEncoding.DecodeString(parts[0])
	if err != nil {
		return nil, fmt.Errorf("decode payload: %w", err)
	}
	sig, err := base64.RawURLEncoding.DecodeString(parts[1])
	if err != nil {
		return nil, fmt.Errorf("decode signature: %w", err)
	}
	if !ed25519.Verify(publicKey, payload, sig) {
		return nil, fmt.Errorf("invalid signature")
	}
	var claims TokenClaims
	if err := json.Unmarshal(payload, &claims); err != nil {
		return nil, fmt.Errorf("unmarshal claims: %w", err)
	}
	if time.Now().Unix() > claims.ExpiresAt {
		return nil, fmt.Errorf("token expired")
	}
	return &claims, nil
}
