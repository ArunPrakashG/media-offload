// Package webhooks handles inbound webhook calls from Razorpay.
package webhooks

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"time"

	"github.com/arunprakashg/nimbus-backend/internal/apiutil"
	"github.com/arunprakashg/nimbus-backend/internal/config"
	"github.com/arunprakashg/nimbus-backend/internal/db"
	"github.com/arunprakashg/nimbus-backend/internal/license"
)

// Handler handles Razorpay webhook events.
type Handler struct {
	cfg     *config.Config
	svc     *license.Service
	database *db.DB
}

// New creates a Handler. webhookSecret is the secret set in the Razorpay dashboard.
func New(cfg *config.Config, svc *license.Service, database *db.DB) *Handler {
	return &Handler{cfg: cfg, svc: svc, database: database}
}

// ── Razorpay payload types ────────────────────────────────────────────────────

type rzpEvent struct {
	Event   string         `json:"event"`
	Payload rzpPayload     `json:"payload"`
}

type rzpPayload struct {
	Subscription rzpEntityWrapper `json:"subscription"`
	Payment      rzpEntityWrapper `json:"payment"`
}

type rzpEntityWrapper struct {
	Entity json.RawMessage `json:"entity"`
}

type rzpSubscription struct {
	ID           string            `json:"id"`
	PlanID       string            `json:"plan_id"`
	Status       string            `json:"status"`
	CurrentStart *int64            `json:"current_start"` // Unix timestamp
	CurrentEnd   *int64            `json:"current_end"`   // Unix timestamp
	Notes        map[string]string `json:"notes"`
}

type rzpPaymentEntity struct {
	ID    string `json:"id"`
	Email string `json:"email"`
}

// ── HTTP handler ──────────────────────────────────────────────────────────────

// HandleRazorpay is the HTTP handler for POST /webhooks/razorpay.
func (h *Handler) HandleRazorpay(w http.ResponseWriter, r *http.Request) {
	// Read body (cap at 2 MiB to avoid memory exhaustion).
	body, err := io.ReadAll(io.LimitReader(r.Body, 2<<20))
	if err != nil {
		apiutil.Error(w, http.StatusBadRequest, "bad_request", "cannot read request body")
		return
	}

	if !h.verifySignature(body, r.Header.Get("X-Razorpay-Signature")) {
		apiutil.Error(w, http.StatusUnauthorized, "unauthorized", "invalid webhook signature")
		return
	}

	var event rzpEvent
	if err := json.Unmarshal(body, &event); err != nil {
		apiutil.Error(w, http.StatusBadRequest, "bad_request", "invalid JSON")
		return
	}

	ctx := r.Context()

	switch event.Event {
	case "subscription.activated", "subscription.charged":
		h.handleSubscriptionActive(ctx, w, event)
	case "subscription.cancelled", "subscription.completed", "subscription.expired":
		h.handleSubscriptionEnded(ctx, w, event)
	default:
		// Acknowledge unknown events so Razorpay doesn't retry.
		w.WriteHeader(http.StatusOK)
	}
}

// ── Event handlers ────────────────────────────────────────────────────────────

func (h *Handler) handleSubscriptionActive(ctx context.Context, w http.ResponseWriter, event rzpEvent) {
	var sub rzpSubscription
	if err := json.Unmarshal(event.Payload.Subscription.Entity, &sub); err != nil {
		apiutil.Error(w, http.StatusBadRequest, "bad_request", "invalid subscription entity")
		return
	}

	var paymentEmail string
	if len(event.Payload.Payment.Entity) > 2 { // non-empty JSON
		var pay rzpPaymentEntity
		if err := json.Unmarshal(event.Payload.Payment.Entity, &pay); err == nil {
			paymentEmail = pay.Email
		}
	}

	email := paymentEmail
	if email == "" {
		email = sub.Notes["email"]
	}
	tier := sub.Notes["tier"]
	if tier == "" {
		tier = "personal" // safe fallback
	}
	billingCycle := sub.Notes["billing_cycle"]
	if billingCycle == "" {
		billingCycle = "monthly"
	}

	var currentStart, currentEnd *time.Time
	if sub.CurrentStart != nil {
		t := time.Unix(*sub.CurrentStart, 0).UTC()
		currentStart = &t
	}
	if sub.CurrentEnd != nil {
		t := time.Unix(*sub.CurrentEnd, 0).UTC()
		currentEnd = &t
	}

	// Upsert the subscription record.
	rzpSub, err := db.UpsertRazorpaySubscription(ctx, h.database.Pool(), db.UpsertRazorpaySubscriptionParams{
		RzpSubID:      sub.ID,
		PlanID:        sub.PlanID,
		Tier:          tier,
		BillingCycle:  billingCycle,
		CustomerEmail: email,
		Status:        sub.Status,
		CurrentStart:  currentStart,
		CurrentEnd:    currentEnd,
	})
	if err != nil {
		slog.Error("webhook: upsert subscription", "err", err, "sub_id", sub.ID)
		apiutil.Error(w, http.StatusInternalServerError, "internal_error", "database error")
		return
	}

	// Check if a license already exists for this subscription.
	existingLic, err := db.GetLicenseBySubscriptionID(ctx, h.database.Pool(), sub.ID)
	if err != nil && !errors.Is(err, db.ErrNotFound) {
		slog.Error("webhook: get license by sub", "err", err)
		apiutil.Error(w, http.StatusInternalServerError, "internal_error", "database error")
		return
	}

	if existingLic != nil {
		// Existing license: update expiry on renewal.
		if err := db.UpdateLicenseExpiry(ctx, h.database.Pool(), existingLic.ID, currentEnd); err != nil {
			slog.Error("webhook: update expiry", "err", err)
		}
		_ = db.InsertLicenseEvent(ctx, h.database.Pool(), existingLic.ID, "subscription_renewed", map[string]any{
			"rzp_sub_id":    sub.ID,
			"current_start": currentStart,
			"current_end":   currentEnd,
		})
		w.WriteHeader(http.StatusOK)
		return
	}

	// No license yet — issue one on first activation.
	if email == "" {
		slog.Warn("webhook: subscription.activated without email", "sub_id", sub.ID)
		// Acknowledge so Razorpay doesn't retry; the license will be issued via verify-payment.
		w.WriteHeader(http.StatusOK)
		return
	}

	resp, err := h.svc.IssueLicense(ctx, license.IssueLicenseRequest{
		Email:          email,
		Tier:           tier,
		ProductSlug:    h.cfg.ProductSlug,
		SubscriptionID: sub.ID,
		BillingCycle:   billingCycle,
	})
	if err != nil {
		slog.Error("webhook: issue license", "err", err, "sub_id", sub.ID)
		// Return 200 to avoid infinite retries on business-logic errors.
		// The issue can be investigated via logs.
		w.WriteHeader(http.StatusOK)
		return
	}

	// Link if the subscription row was just upserted (may not have license_id yet).
	if rzpSub.LicenseID == nil {
		if linkErr := db.LinkSubscriptionToLicense(ctx, h.database.Pool(), sub.ID, resp.Key); linkErr != nil {
			slog.Warn("webhook: link subscription", "err", linkErr)
		}
	}

	slog.Info("webhook: license issued", "key_prefix", resp.Key[:8], "tier", tier, "sub_id", sub.ID)
	w.WriteHeader(http.StatusOK)
}

func (h *Handler) handleSubscriptionEnded(ctx context.Context, w http.ResponseWriter, event rzpEvent) {
	var sub rzpSubscription
	if err := json.Unmarshal(event.Payload.Subscription.Entity, &sub); err != nil {
		apiutil.Error(w, http.StatusBadRequest, "bad_request", "invalid subscription entity")
		return
	}

	lic, err := db.GetLicenseBySubscriptionID(ctx, h.database.Pool(), sub.ID)
	if err != nil {
		if errors.Is(err, db.ErrNotFound) {
			w.WriteHeader(http.StatusOK) // nothing to do
			return
		}
		apiutil.Error(w, http.StatusInternalServerError, "internal_error", "database error")
		return
	}

	_, err = h.database.Pool().Exec(ctx,
		`UPDATE licenses SET status = 'suspended' WHERE id = $1::uuid AND status = 'active'`,
		lic.ID)
	if err != nil {
		slog.Error("webhook: suspend license", "err", err, "lic_id", lic.ID)
	}

	_ = db.InsertLicenseEvent(ctx, h.database.Pool(), lic.ID, "subscription_ended", map[string]any{
		"event":      event.Event,
		"rzp_sub_id": sub.ID,
	})

	slog.Info("webhook: license suspended", "lic_id", lic.ID, "event", event.Event)
	w.WriteHeader(http.StatusOK)
}

// ── Signature verification ────────────────────────────────────────────────────

func (h *Handler) verifySignature(body []byte, signature string) bool {
	if h.cfg.RazorpayWebhookSecret == "" {
		// In development with no secret configured, skip verification.
		return true
	}
	mac := hmac.New(sha256.New, []byte(h.cfg.RazorpayWebhookSecret))
	mac.Write(body)
	expected := hex.EncodeToString(mac.Sum(nil))
	return hmac.Equal([]byte(expected), []byte(signature))
}
