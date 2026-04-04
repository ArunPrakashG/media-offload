// Package checkout handles the public checkout endpoints used by the marketing
// site to create Razorpay subscriptions and verify payment signatures.
// Keeping this logic in the backend means the Razorpay key secret never touches
// the frontend or any Astro/Vercel edge environment.
package checkout

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"strings"

	"github.com/arunprakashg/nimbus-backend/internal/apiutil"
	"github.com/arunprakashg/nimbus-backend/internal/config"
	"github.com/arunprakashg/nimbus-backend/internal/license"
)

var tierLabels = map[string]string{
	"personal": "Personal",
	"pro":      "Pro",
	"agency":   "Agency",
}

// Handler handles checkout-related HTTP endpoints.
type Handler struct {
	cfg    *config.Config
	svc    *license.Service
	client *http.Client
}

// New creates a Handler.
func New(cfg *config.Config, svc *license.Service) *Handler {
	return &Handler{cfg: cfg, svc: svc, client: &http.Client{}}
}

// ── POST /v1/checkout/create-subscription ────────────────────────────────────

type createSubRequest struct {
	Plan  string `json:"plan"`
	Email string `json:"email"`
}

type createSubResponse struct {
	SubscriptionID string `json:"subscriptionId"`
	KeyID          string `json:"keyId"`
	Plan           string `json:"plan"`
	BillingCycle   string `json:"billingCycle"`
	PlanLabel      string `json:"planLabel"`
}

// CreateSubscription creates a Razorpay subscription and returns the details
// the frontend needs to open the Razorpay checkout widget.
func (h *Handler) CreateSubscription(w http.ResponseWriter, r *http.Request) {
	var body createSubRequest
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		apiutil.Error(w, http.StatusBadRequest, "bad_request", "invalid JSON")
		return
	}

	plan := strings.ToLower(strings.TrimSpace(body.Plan))
	email := strings.TrimSpace(body.Email)

	label, ok := tierLabels[plan]
	if !ok {
		apiutil.Error(w, http.StatusBadRequest, "invalid_plan", "unknown plan: "+plan)
		return
	}

	if h.cfg.RazorpayKeyID == "" || h.cfg.RazorpayKeySecret == "" {
		apiutil.Error(w, http.StatusServiceUnavailable, "not_configured", "payment gateway not configured")
		return
	}

	planID := h.cfg.RazorpayPlans[plan+"_annual"]
	if planID == "" {
		apiutil.Error(w, http.StatusServiceUnavailable, "not_configured",
			fmt.Sprintf("plan ID for %s is not configured — contact support", plan))
		return
	}

	subID, err := h.createRazorpaySubscription(r.Context(), planID, plan, email)
	if err != nil {
		apiutil.Error(w, http.StatusBadGateway, "gateway_error", "failed to create subscription — please try again")
		return
	}

	apiutil.JSON(w, http.StatusOK, createSubResponse{
		SubscriptionID: subID,
		KeyID:          h.cfg.RazorpayKeyID,
		Plan:           plan,
		BillingCycle:   "annual",
		PlanLabel:      label,
	})
}

// createRazorpaySubscription calls the Razorpay REST API to create a subscription.
func (h *Handler) createRazorpaySubscription(ctx context.Context, planID, tier, email string) (string, error) {
	payload := map[string]any{
		"plan_id":     planID,
		"total_count": 10, // up to 10 annual renewal cycles (~10 years)
		"quantity":    1,
		"notes": map[string]string{
			"tier":          tier,
			"billing_cycle": "annual",
			"email":         email,
		},
	}

	bodyBytes, err := json.Marshal(payload)
	if err != nil {
		return "", err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost,
		"https://api.razorpay.com/v1/subscriptions", bytes.NewReader(bodyBytes))
	if err != nil {
		return "", err
	}
	req.SetBasicAuth(h.cfg.RazorpayKeyID, h.cfg.RazorpayKeySecret)
	req.Header.Set("Content-Type", "application/json")

	resp, err := h.client.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()

	if resp.StatusCode >= 300 {
		return "", fmt.Errorf("razorpay returned HTTP %d", resp.StatusCode)
	}

	var rzpResp struct {
		ID string `json:"id"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&rzpResp); err != nil {
		return "", err
	}
	if rzpResp.ID == "" {
		return "", errors.New("razorpay returned empty subscription ID")
	}
	return rzpResp.ID, nil
}

// ── POST /v1/checkout/verify-payment ─────────────────────────────────────────

type verifyPaymentRequest struct {
	SubscriptionID string `json:"subscriptionId"`
	PaymentID      string `json:"paymentId"`
	Signature      string `json:"signature"`
	Plan           string `json:"plan"`
	BillingCycle   string `json:"billingCycle"`
	Email          string `json:"email"`
}

// VerifyPayment verifies the Razorpay payment signature and issues a license.
// It returns the license key on success so the frontend can display it
// immediately (the webhook will also try to issue one, but idempotent upsert
// ensures only one license is created).
func (h *Handler) VerifyPayment(w http.ResponseWriter, r *http.Request) {
	var body verifyPaymentRequest
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		apiutil.Error(w, http.StatusBadRequest, "bad_request", "invalid JSON")
		return
	}

	if body.SubscriptionID == "" || body.PaymentID == "" || body.Signature == "" {
		apiutil.Error(w, http.StatusBadRequest, "bad_request",
			"subscriptionId, paymentId, and signature are required")
		return
	}

	if !h.verifySignature(body.PaymentID, body.SubscriptionID, body.Signature) {
		apiutil.Error(w, http.StatusUnauthorized, "invalid_signature", "payment verification failed")
		return
	}

	plan := strings.ToLower(strings.TrimSpace(body.Plan))
	billingCycle := body.BillingCycle
	if billingCycle == "" {
		billingCycle = "annual"
	}

	resp, err := h.svc.IssueLicense(r.Context(), license.IssueLicenseRequest{
		Email:          strings.TrimSpace(body.Email),
		Tier:           plan,
		ProductSlug:    h.cfg.ProductSlug,
		PaymentID:      body.PaymentID,
		SubscriptionID: body.SubscriptionID,
		BillingCycle:   billingCycle,
	})
	if err != nil {
		apiutil.Error(w, http.StatusInternalServerError, "license_error",
			"payment captured but license issuance failed — email hello@usenimbus.dev with your payment ID")
		return
	}

	apiutil.JSON(w, http.StatusOK, map[string]string{"license_key": resp.Key})
}

// verifySignature checks the Razorpay payment signature using a timing-safe
// comparison. Razorpay computes HMAC-SHA256(paymentId+"|"+subscriptionId, key_secret).
func (h *Handler) verifySignature(paymentID, subscriptionID, signature string) bool {
	mac := hmac.New(sha256.New, []byte(h.cfg.RazorpayKeySecret))
	mac.Write([]byte(paymentID + "|" + subscriptionID))
	expected := hex.EncodeToString(mac.Sum(nil))
	// hmac.Equal does a constant-time comparison to prevent timing attacks.
	return hmac.Equal([]byte(expected), []byte(signature))
}
