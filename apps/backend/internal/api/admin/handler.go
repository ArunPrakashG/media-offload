package admin

import (
	"encoding/json"
	"errors"
	"net/http"

	"github.com/arunprakashg/nimbus-backend/internal/apiutil"
	"github.com/arunprakashg/nimbus-backend/internal/db"
	"github.com/arunprakashg/nimbus-backend/internal/license"
)

// Handler handles admin-only license management endpoints.
type Handler struct {
	svc         *license.Service
	database    *db.DB
	productSlug string
}

// New creates a Handler with the given service and product slug.
func New(svc *license.Service, database *db.DB, productSlug string) *Handler {
	return &Handler{svc: svc, database: database, productSlug: productSlug}
}

type issueRequest struct {
	Email          string `json:"email"`
	Tier           string `json:"tier"`
	PaymentID      string `json:"payment_id"`
	OrderID        string `json:"order_id"`
	SubscriptionID string `json:"subscription_id"`  // Razorpay subscription ID (optional)
	BillingCycle   string `json:"billing_cycle"`    // "monthly" | "annual" (default: "monthly")
}

type issueResponse struct {
	LicenseKey string `json:"license_key"`
	Tier       string `json:"tier"`
	Email      string `json:"email"`
}

// IssueLicense handles POST /admin/v1/licenses/issue.
// It creates a new license for the customer identified by email after a
// successful payment, and returns the license key.
func (h *Handler) IssueLicense(w http.ResponseWriter, r *http.Request) {
	var body issueRequest
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		apiutil.Error(w, http.StatusBadRequest, "invalid_request", "request body must be valid JSON")
		return
	}
	if body.Email == "" || body.Tier == "" {
		apiutil.Error(w, http.StatusBadRequest, "invalid_request", "email and tier are required")
		return
	}

	resp, err := h.svc.IssueLicense(r.Context(), license.IssueLicenseRequest{
		Email:          body.Email,
		Tier:           body.Tier,
		ProductSlug:    h.productSlug,
		PaymentID:      body.PaymentID,
		OrderID:        body.OrderID,
		SubscriptionID: body.SubscriptionID,
		BillingCycle:   body.BillingCycle,
	})
	if err != nil {
		switch {
		case errors.Is(err, license.ErrUnknownTier):
			apiutil.Error(w, http.StatusBadRequest, "unknown_tier", err.Error())
		case errors.Is(err, license.ErrProductNotFound):
			apiutil.Error(w, http.StatusBadRequest, "product_not_found", err.Error())
		default:
			apiutil.Error(w, http.StatusInternalServerError, "internal_error", "an internal error occurred")
		}
		return
	}

	apiutil.JSON(w, http.StatusCreated, issueResponse{
		LicenseKey: resp.Key,
		Tier:       resp.Tier,
		Email:      resp.Email,
	})
}

// ── POST /admin/v1/releases ───────────────────────────────────────────────────

type createReleaseRequest struct {
	Version       string `json:"version"`
	R2ObjectKey   string `json:"r2_object_key"` // e.g. "releases/nimbus-pro/0.2.0/nimbus-pro.zip"
	Changelog     string `json:"changelog"`
	MinWPVersion  string `json:"min_wp_version"`
	MinPHPVersion string `json:"min_php_version"`
	TestedUpTo    string `json:"tested_up_to"`
}

type createReleaseResponse struct {
	ID      string `json:"id"`
	Version string `json:"version"`
}

// CreateRelease handles POST /admin/v1/releases.
//
// Registers a new plugin version so licensed WordPress sites can discover and
// install it via the built-in update mechanism on their next update check.
//
// Typical deployment workflow:
//  1. Build the plugin zip locally.
//  2. Upload the zip to your S3/R2 bucket and copy the public or presigned URL.
//  3. POST this endpoint with the version and URL — all active licensees will
//     see the update badge on their next WP update cycle (~12 h).
func (h *Handler) CreateRelease(w http.ResponseWriter, r *http.Request) {
	var body createReleaseRequest
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		apiutil.Error(w, http.StatusBadRequest, "invalid_request", "request body must be valid JSON")
		return
	}
	if body.Version == "" || body.R2ObjectKey == "" {
		apiutil.Error(w, http.StatusBadRequest, "invalid_request", "version and r2_object_key are required")
		return
	}

	// Resolve the product by its slug.
	product, err := db.GetProductBySlug(r.Context(), h.database.Pool(), h.productSlug)
	if err != nil {
		if errors.Is(err, db.ErrNotFound) {
			apiutil.Error(w, http.StatusBadRequest, "product_not_found",
				"product slug '"+h.productSlug+"' not found in database")
			return
		}
		apiutil.Error(w, http.StatusInternalServerError, "internal_error", "could not look up product")
		return
	}

	minWP := body.MinWPVersion
	if minWP == "" {
		minWP = "6.0"
	}
	minPHP := body.MinPHPVersion
	if minPHP == "" {
		minPHP = "8.0"
	}

	rel, err := db.CreateRelease(r.Context(), h.database.Pool(), db.CreateReleaseParams{
		ProductID:     product.ID,
		Version:       body.Version,
		R2ObjectKey:   body.R2ObjectKey,
		Changelog:     body.Changelog,
		MinWPVersion:  minWP,
		MinPHPVersion: minPHP,
		TestedUpTo:    body.TestedUpTo,
	})
	if err != nil {
		apiutil.Error(w, http.StatusInternalServerError, "internal_error", "could not create release")
		return
	}

	apiutil.JSON(w, http.StatusCreated, createReleaseResponse{
		ID:      rel.ID,
		Version: rel.Version,
	})
}
