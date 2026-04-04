package license

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
)

// GenerateKey creates a cryptographically random license key.
//
//	GenerateKey("nmb_live") → "nmb_live_a3f8b2c1d4e5f67890abcd1234567890"
//	GenerateKey("nmb_test") → "nmb_test_..."
func GenerateKey(prefix string) (string, error) {
	b := make([]byte, 16) // 128 bits of entropy
	if _, err := rand.Read(b); err != nil {
		return "", fmt.Errorf("generate random bytes: %w", err)
	}
	return prefix + "_" + hex.EncodeToString(b), nil
}
