package middleware

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/redis/go-redis/v9"
)

// hmacWindow is the maximum age (or future drift) we allow for signed requests.
// Requests older than 5 minutes or more than 60 seconds in the future are rejected.
const hmacWindow = 5 * time.Minute

// VerifyRequestHMAC validates the HMAC-SHA256 signature on an inbound license request.
//
// Every plugin-to-backend request must include three headers:
//   - X-Nimbus-Timestamp — Unix timestamp (integer seconds, string)
//   - X-Nimbus-Nonce     — 16–64 char random hex string (32 recommended)
//   - X-Nimbus-Signature — lowercase hex HMAC-SHA256 of the canonical string
//
// Canonical string format (joined with newline, no trailing newline):
//
//	NIMBUS-HMAC-V1
//	<HTTP_METHOD>
//	<URL_PATH>          (no query string)
//	<TIMESTAMP>
//	<NONCE>
//	<HEX(SHA256(body))> (SHA256 of empty string when body is empty)
//
// The HMAC key is the raw license key string submitted in the request body.
// Nonces are stored in Redis for hmacWindow+1 minute to prevent replays.
// If Redis is unavailable the nonce check is skipped (fail-open) to avoid a
// Redis outage bricking all activations.
func VerifyRequestHMAC(r *http.Request, body []byte, key string, rdb *redis.Client) error {
	ts := strings.TrimSpace(r.Header.Get("X-Nimbus-Timestamp"))
	nonce := strings.TrimSpace(r.Header.Get("X-Nimbus-Nonce"))
	sig := strings.TrimSpace(r.Header.Get("X-Nimbus-Signature"))

	if ts == "" || nonce == "" || sig == "" {
		return errMissingHMACHeaders
	}

	// ── 1. Timestamp window ────────────────────────────────────────────────
	tsInt, err := strconv.ParseInt(ts, 10, 64)
	if err != nil {
		return errInvalidTimestamp
	}
	diff := time.Now().Unix() - tsInt
	if diff < -60 || diff > int64(hmacWindow.Seconds()) {
		return errTimestampOutOfWindow
	}

	// ── 2. Nonce format ─────────────────────────────────────────────────────
	if len(nonce) < 16 || len(nonce) > 64 {
		return errInvalidNonce
	}

	// ── 3. Nonce uniqueness (replay prevention) ──────────────────────────────
	if rdb != nil {
		nonceKey := "hmac:nonce:" + nonce
		set, redisErr := rdb.SetNX(r.Context(), nonceKey, "1", hmacWindow+time.Minute).Result()
		if redisErr == nil && !set {
			return errReplayDetected
		}
		// If redisErr != nil we fail-open (Redis outage ≠ deny all activations).
	}

	// ── 4. Compute expected signature ───────────────────────────────────────
	sum := sha256.Sum256(body)
	bodyHash := hex.EncodeToString(sum[:])

	canonical := strings.Join([]string{
		"NIMBUS-HMAC-V1",
		r.Method,
		r.URL.Path,
		ts,
		nonce,
		bodyHash,
	}, "\n")

	mac := hmac.New(sha256.New, []byte(key))
	mac.Write([]byte(canonical))
	expected := hex.EncodeToString(mac.Sum(nil))

	// Constant-time comparison prevents timing side-channels.
	if !hmac.Equal([]byte(sig), []byte(expected)) {
		return errInvalidHMACSignature
	}

	return nil
}

var (
	errMissingHMACHeaders  = errors.New("missing HMAC authentication headers (X-Nimbus-Timestamp, X-Nimbus-Nonce, X-Nimbus-Signature)")
	errInvalidTimestamp    = errors.New("X-Nimbus-Timestamp is not a valid Unix integer")
	errTimestampOutOfWindow = errors.New("request timestamp is outside the allowed window (±5 min)")
	errInvalidNonce        = errors.New("X-Nimbus-Nonce must be 16–64 hex characters")
	errReplayDetected      = errors.New("nonce already used — possible replay attack")
	errInvalidHMACSignature = errors.New("X-Nimbus-Signature does not match — request tampered or key mismatch")
)
