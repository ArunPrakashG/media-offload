package licenses

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"time"

	"github.com/redis/go-redis/v9"

	"github.com/arunprakashg/nimbus-backend/internal/api"
	"github.com/arunprakashg/nimbus-backend/internal/license"
)

// Handler holds the dependencies for the license HTTP handlers.
type Handler struct {
	svc *license.Service
	rdb *redis.Client
}

// New creates a Handler.
func New(svc *license.Service, rdb *redis.Client) *Handler {
	return &Handler{svc: svc, rdb: rdb}
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
func (h *Handler) Activate(w http.ResponseWriter, r *http.Request) {
	var body activateRequest
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		api.Error(w, http.StatusBadRequest, "invalid_request", "request body must be valid JSON")
		return
	}
	if body.Key == "" || body.Domain == "" || body.Product == "" {
		api.Error(w, http.StatusBadRequest, "invalid_request", "key, domain, and product are required")
		return
	}

	if !h.allowActivate(r, body.Key) {
		api.Error(w, http.StatusTooManyRequests, "rate_limited", "too many activation attempts; retry in 1 minute")
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

	api.JSON(w, http.StatusOK, activateResponse{
		Token:     resp.Token,
		ExpiresAt: resp.ExpiresAt,
		Tier:      resp.Tier,
		Features:  resp.Features,
	})
}

// ── POST /v1/licenses/deactivate ─────────────────────────────────────────────

type deactivateRequest struct {
	Key    string `json:"key"`
	Domain string `json:"domain"`
}

// Deactivate removes a domain from a license's active slots.
func (h *Handler) Deactivate(w http.ResponseWriter, r *http.Request) {
	var body deactivateRequest
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		api.Error(w, http.StatusBadRequest, "invalid_request", "request body must be valid JSON")
		return
	}
	if body.Key == "" || body.Domain == "" {
		api.Error(w, http.StatusBadRequest, "invalid_request", "key and domain are required")
		return
	}

	if err := h.svc.Deactivate(r.Context(), body.Key, body.Domain); err != nil {
		h.mapServiceError(w, err)
		return
	}

	api.JSON(w, http.StatusOK, map[string]string{"status": "deactivated"})
}

// ── GET /v1/licenses/status ───────────────────────────────────────────────────

type statusResponse struct {
	Status         string     `json:"status"`
	Tier           string     `json:"tier"`
	MaxActivations int        `json:"max_activations"`
	ActiveDomains  []string   `json:"active_domains"`
	ExpiresAt      *time.Time `json:"expires_at"`
}

// Status returns the current state of a license key (no token issued).
func (h *Handler) Status(w http.ResponseWriter, r *http.Request) {
	key := r.URL.Query().Get("key")
	if key == "" {
		api.Error(w, http.StatusBadRequest, "invalid_request", "query parameter 'key' is required")
		return
	}

	resp, err := h.svc.Status(r.Context(), key)
	if err != nil {
		h.mapServiceError(w, err)
		return
	}

	domains := resp.ActiveDomains
	if domains == nil {
		domains = []string{}
	}
	api.JSON(w, http.StatusOK, statusResponse{
		Status:         resp.Status,
		Tier:           resp.Tier,
		MaxActivations: resp.MaxActivations,
		ActiveDomains:  domains,
		ExpiresAt:      resp.ExpiresAt,
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
		api.Error(w, http.StatusNotFound, "key_not_found", err.Error())
	case errors.Is(err, license.ErrKeyExpired):
		api.Error(w, http.StatusForbidden, "key_expired", err.Error())
	case errors.Is(err, license.ErrKeyRevoked):
		api.Error(w, http.StatusForbidden, "key_revoked", err.Error())
	case errors.Is(err, license.ErrProductMismatch):
		api.Error(w, http.StatusForbidden, "product_mismatch", err.Error())
	case errors.Is(err, license.ErrMaxActivationsReached):
		api.Error(w, http.StatusForbidden, "max_activations_reached", err.Error())
	default:
		api.Error(w, http.StatusInternalServerError, "internal_error", "an internal error occurred")
	}
}
