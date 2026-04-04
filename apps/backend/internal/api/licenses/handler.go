package licenses

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"time"

	"github.com/redis/go-redis/v9"

	"github.com/arunprakashg/nimbus-backend/internal/api/middleware"
	"github.com/arunprakashg/nimbus-backend/internal/apiutil"
	"github.com/arunprakashg/nimbus-backend/internal/license"
)

// Handler holds the dependencies for the license HTTP handlers.
type Handler struct {
	svc         *license.Service
	rdb         *redis.Client
	productSlug string
}

// New creates a Handler.
func New(svc *license.Service, rdb *redis.Client, productSlug string) *Handler {
	return &Handler{svc: svc, rdb: rdb, productSlug: productSlug}
}

// ── POST /v1/licenses/activate ────────────────────────────────────────────────

type activateRequest struct {
	Key           string `json:"key"`
	Domain        string `json:"domain"`
	Product       string `json:"product"`
	PluginVersion string `json:"plugin_version"`
}

type activateResponse struct {
	Token     string    `json:"token"`
	ExpiresAt time.Time `json:"expires_at"`
	Tier      string    `json:"tier"`
	Features  []string  `json:"features"`
}

// Activate validates a license key for a domain and returns a signed token.
//
// Security: every activate request must carry HMAC-SHA256 headers
// (X-Nimbus-Timestamp, X-Nimbus-Nonce, X-Nimbus-Signature) signed with the
// license key as the shared secret. This prevents forged activation requests
// from anyone who doesn't actually possess the key.
func (h *Handler) Activate(w http.ResponseWriter, r *http.Request) {
	// Buffer the body so it can be used for both HMAC verification and JSON decode.
	bodyBytes, err := io.ReadAll(io.LimitReader(r.Body, 1<<16)) // 64 KiB max
	if err != nil {
		apiutil.Error(w, http.StatusBadRequest, "invalid_request", "could not read request body")
		return
	}

	var body activateRequest
	if err := json.Unmarshal(bodyBytes, &body); err != nil {
		apiutil.Error(w, http.StatusBadRequest, "invalid_request", "request body must be valid JSON")
		return
	}
	if body.Key == "" || body.Domain == "" || body.Product == "" {
		apiutil.Error(w, http.StatusBadRequest, "invalid_request", "key, domain, and product are required")
		return
	}

	// ── HMAC verification ─────────────────────────────────────────────────────
	// The request must be signed with the license key so the backend can confirm
	// the caller actually possesses the key, not just its hash or a guess.
	if err := middleware.VerifyRequestHMAC(r, bodyBytes, body.Key, h.rdb); err != nil {
		apiutil.Error(w, http.StatusUnauthorized, "invalid_signature", err.Error())
		return
	}

	// ── Rate limiting (per IP + key) ─────────────────────────────────────────
	if !h.allowActivate(r, body.Key) {
		apiutil.Error(w, http.StatusTooManyRequests, "rate_limited", "too many activation attempts; retry in 1 minute")
		return
	}

	resp, err := h.svc.Activate(r.Context(), license.ActivateRequest{
		Key:           body.Key,
		Domain:        body.Domain,
		Product:       body.Product,
		PluginVersion: body.PluginVersion,
	})
	if err != nil {
		h.mapServiceError(w, err)
		return
	}

	apiutil.JSON(w, http.StatusOK, activateResponse{
		Token:     resp.Token,
		ExpiresAt: resp.ExpiresAt,
		Tier:      resp.Tier,
		Features:  resp.Features,
	})
}

// ── POST /v1/licenses/deactivate ─────────────────────────────────────────────

type deactivateRequest struct {
	Domain string `json:"domain"`
}

// Deactivate removes a domain from a license's active slots.
//
// Security: requires a valid Ed25519 activation token (Authorization: Bearer …).
// The token embeds the license UUID so no raw key is needed — and the backend
// verifies the token signature before touching the database.
func (h *Handler) Deactivate(w http.ResponseWriter, r *http.Request) {
	// Claims were validated by the RequireActivationToken middleware.
	claims := middleware.ClaimsFromContext(r.Context())
	if claims == nil {
		apiutil.Error(w, http.StatusUnauthorized, "unauthorized", "activation token required")
		return
	}

	var body deactivateRequest
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		apiutil.Error(w, http.StatusBadRequest, "invalid_request", "request body must be valid JSON")
		return
	}
	if body.Domain == "" {
		apiutil.Error(w, http.StatusBadRequest, "invalid_request", "domain is required")
		return
	}

	if err := h.svc.DeactivateByID(r.Context(), claims.LicenseID, body.Domain); err != nil {
		h.mapServiceError(w, err)
		return
	}

	apiutil.JSON(w, http.StatusOK, map[string]string{"status": "deactivated"})
}

// ── GET /v1/licenses/status ───────────────────────────────────────────────────

type statusResponse struct {
	Status         string     `json:"status"`
	Tier           string     `json:"tier"`
	MaxActivations int        `json:"max_activations"`
	ActiveDomains  []string   `json:"active_domains"`
	ExpiresAt      *time.Time `json:"expires_at"`
}

// Status returns the current state of a license (no new token issued).
//
// Security: requires a valid Ed25519 activation token (Authorization: Bearer …).
func (h *Handler) Status(w http.ResponseWriter, r *http.Request) {
	claims := middleware.ClaimsFromContext(r.Context())
	if claims == nil {
		apiutil.Error(w, http.StatusUnauthorized, "unauthorized", "activation token required")
		return
	}

	resp, err := h.svc.Status(r.Context(), claims.LicenseID)
	if err != nil {
		h.mapServiceError(w, err)
		return
	}

	domains := resp.ActiveDomains
	if domains == nil {
		domains = []string{}
	}
	apiutil.JSON(w, http.StatusOK, statusResponse{
		Status:         resp.Status,
		Tier:           resp.Tier,
		MaxActivations: resp.MaxActivations,
		ActiveDomains:  domains,
		ExpiresAt:      resp.ExpiresAt,
	})
}

// ── GET /v1/licenses/activations ─────────────────────────────────────────────

type activationItem struct {
	Domain      string    `json:"domain"`
	ActivatedAt time.Time `json:"activated_at"`
	LastSeenAt  time.Time `json:"last_seen_at"`
}

type activationsResponse struct {
	Tier           string           `json:"tier"`
	MaxActivations int              `json:"max_activations"`
	Activations    []activationItem `json:"activations"`
}

// Activations returns the list of domains that have activated the given license.
//
// Security: requires a valid Ed25519 activation token (Authorization: Bearer …).
// The license UUID in the token determines which license is queried — the raw
// key is never transmitted for this endpoint.
func (h *Handler) Activations(w http.ResponseWriter, r *http.Request) {
	claims := middleware.ClaimsFromContext(r.Context())
	if claims == nil {
		apiutil.Error(w, http.StatusUnauthorized, "unauthorized", "activation token required")
		return
	}

	result, err := h.svc.ListActivationsByID(r.Context(), claims.LicenseID)
	if err != nil {
		if errors.Is(err, license.ErrKeyNotFound) {
			apiutil.Error(w, http.StatusNotFound, "key_not_found", "license not found")
			return
		}
		apiutil.Error(w, http.StatusInternalServerError, "internal_error", "an internal error occurred")
		return
	}

	items := make([]activationItem, 0, len(result.Activations))
	for _, a := range result.Activations {
		items = append(items, activationItem{
			Domain:      a.Domain,
			ActivatedAt: a.ActivatedAt,
			LastSeenAt:  a.LastSeenAt,
		})
	}

	apiutil.JSON(w, http.StatusOK, activationsResponse{
		Tier:           result.Tier,
		MaxActivations: result.MaxActivations,
		Activations:    items,
	})
}

// ── Helpers ───────────────────────────────────────────────────────────────────

// allowActivate enforces 10 activation attempts per (IP, key) per minute
// using a Redis INCR counter with a 1-minute TTL.
// Returns true when the request is within the limit.
func (h *Handler) allowActivate(r *http.Request, key string) bool {
	rateKey := fmt.Sprintf("rl:activate:%s:%s", r.RemoteAddr, key)
	count, err := h.rdb.Incr(r.Context(), rateKey).Result()
	if err != nil {
		return true // fail-open on Redis errors
	}
	if count == 1 {
		h.rdb.Expire(r.Context(), rateKey, time.Minute)
	}
	return count <= 10
}

func (h *Handler) mapServiceError(w http.ResponseWriter, err error) {
	switch {
	case errors.Is(err, license.ErrKeyNotFound):
		apiutil.Error(w, http.StatusNotFound, "key_not_found", err.Error())
	case errors.Is(err, license.ErrKeyExpired):
		apiutil.Error(w, http.StatusForbidden, "key_expired", err.Error())
	case errors.Is(err, license.ErrKeyRevoked):
		apiutil.Error(w, http.StatusForbidden, "key_revoked", err.Error())
	case errors.Is(err, license.ErrProductMismatch):
		apiutil.Error(w, http.StatusForbidden, "product_mismatch", err.Error())
	case errors.Is(err, license.ErrMaxActivationsReached):
		apiutil.Error(w, http.StatusForbidden, "max_activations_reached", err.Error())
	case errors.Is(err, license.ErrProductNotFound):
		apiutil.Error(w, http.StatusBadRequest, "product_not_found", err.Error())
	default:
		apiutil.Error(w, http.StatusInternalServerError, "internal_error", "an internal error occurred")
	}
}

// ── POST /v1/licenses/register-free ──────────────────────────────────────────

type registerFreeRequest struct {
	Email  string `json:"email"`
	Domain string `json:"domain"`
}

type registerFreeResponse struct {
	Token     string    `json:"token"`
	ExpiresAt time.Time `json:"expires_at"`
	Tier      string    `json:"tier"`
	Features  []string  `json:"features"`
}

// RegisterFree issues a free-tier license for the given email+domain pair and
// returns a signed activation token. Subsequent calls for the same email+domain
// are idempotent. Rate-limited to 5 requests per IP per minute.
func (h *Handler) RegisterFree(w http.ResponseWriter, r *http.Request) {
	if !h.allowFreeRegister(r) {
		apiutil.Error(w, http.StatusTooManyRequests, "rate_limited", "too many requests; retry in 1 minute")
		return
	}

	var body registerFreeRequest
	if err := json.NewDecoder(io.LimitReader(r.Body, 4096)).Decode(&body); err != nil {
		apiutil.Error(w, http.StatusBadRequest, "invalid_request", "request body must be valid JSON")
		return
	}
	if body.Email == "" || body.Domain == "" {
		apiutil.Error(w, http.StatusBadRequest, "invalid_request", "email and domain are required")
		return
	}

	resp, err := h.svc.RegisterFree(r.Context(), body.Email, body.Domain, h.productSlug)
	if err != nil {
		h.mapServiceError(w, err)
		return
	}

	apiutil.JSON(w, http.StatusOK, registerFreeResponse{
		Token:     resp.Token,
		ExpiresAt: resp.ExpiresAt,
		Tier:      resp.Tier,
		Features:  resp.Features,
	})
}

// allowFreeRegister rate-limits free registrations to 5 per IP per minute.
func (h *Handler) allowFreeRegister(r *http.Request) bool {
	ip := r.RemoteAddr
	rateKey := fmt.Sprintf("rl:register_free:%s", ip)
	count, err := h.rdb.Incr(r.Context(), rateKey).Result()
	if err != nil {
		return true // fail-open on Redis errors
	}
	if count == 1 {
		h.rdb.Expire(r.Context(), rateKey, time.Minute)
	}
	return count <= 5
}
