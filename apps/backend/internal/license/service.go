package license

import (
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"errors"
	"fmt"
	"time"

	"github.com/arunprakashg/nimbus-backend/internal/config"
	"github.com/arunprakashg/nimbus-backend/internal/db"
)

// TierFeatures maps tier name to the feature set embedded in the signed token.
// The WordPress plugin reads the "features" claim to gate functionality.
//
// Feature keys used by the plugin:
//
//	offload          — core S3 offloading (all plans including free)
//	db_rewrite       — database URL rewriting (all plans including free)
//	bulk_migration   — bulk migration tool for existing media (paid plans only)
//	nightly_batch    — nightly background offload job (paid plans only)
//	multisite        — WordPress Multisite support (pro+)
//	wpcli            — WP-CLI commands (pro+)
//	cdn              — CDN delivery settings (pro+)
//	cache_purge      — proxy cache purge integration (agency)
//	priority_support — priority email support flag (pro+)
var TierFeatures = map[string][]string{
	"free":     {"offload", "db_rewrite"},
	"personal": {"offload", "db_rewrite", "bulk_migration", "nightly_batch", "restore_local", "selective_offload"},
	"pro":      {"offload", "db_rewrite", "bulk_migration", "nightly_batch", "restore_local", "selective_offload", "storage_analytics", "cdn", "multisite", "wpcli", "priority_support"},
	"agency":   {"offload", "db_rewrite", "bulk_migration", "nightly_batch", "restore_local", "selective_offload", "storage_analytics", "cdn", "multisite", "wpcli", "priority_support", "cache_purge", "multi_bucket"},
}

// Sentinel errors for the API layer to map to HTTP status codes.
var (
	ErrKeyNotFound           = errors.New("license key not found")
	ErrKeyExpired            = errors.New("license key has expired")
	ErrKeyRevoked            = errors.New("license key has been revoked or suspended")
	ErrProductMismatch       = errors.New("key is not valid for this product")
	ErrMaxActivationsReached = errors.New("maximum activations reached for this key")
)

// Service is the core domain object for license operations.
type Service struct {
	db          *db.DB
	privateKey  ed25519.PrivateKey
	publicKey   ed25519.PublicKey
	tokenTTL    time.Duration
	gracePeriod time.Duration // added to exp → gau claim; defined by server config
}

// PublicKey returns the Ed25519 public key used to verify activation tokens.
// The router passes this to the token-auth middleware so it can verify tokens
// without touching the database.
func (s *Service) PublicKey() ed25519.PublicKey { return s.publicKey }

// NewService constructs a Service, decoding the Ed25519 keys from the config.
func NewService(database *db.DB, cfg *config.Config) (*Service, error) {
	privBytes, err := base64.StdEncoding.DecodeString(cfg.LicensePrivateKey)
	if err != nil {
		return nil, fmt.Errorf("decode private key: %w", err)
	}
	if len(privBytes) != ed25519.PrivateKeySize {
		return nil, fmt.Errorf("private key must be %d bytes, got %d",
			ed25519.PrivateKeySize, len(privBytes))
	}
	pubBytes, err := base64.StdEncoding.DecodeString(cfg.LicensePublicKey)
	if err != nil {
		return nil, fmt.Errorf("decode public key: %w", err)
	}
	if len(pubBytes) != ed25519.PublicKeySize {
		return nil, fmt.Errorf("public key must be %d bytes, got %d",
			ed25519.PublicKeySize, len(pubBytes))
	}
	return &Service{
		db:          database,
		privateKey:  ed25519.PrivateKey(privBytes),
		publicKey:   ed25519.PublicKey(pubBytes),
		tokenTTL:    time.Duration(cfg.TokenTTLDays) * 24 * time.Hour,
		gracePeriod: time.Duration(cfg.TokenGracePeriodDays) * 24 * time.Hour,
	}, nil
}

// ── Activate ──────────────────────────────────────────────────────────────────

// ActivateRequest is the input for a license activation.
type ActivateRequest struct {
	Key           string
	Domain        string
	Product       string
	PluginVersion string
}

// ActivateResponse is returned on a successful activation.
type ActivateResponse struct {
	Token     string
	ExpiresAt time.Time
	Tier      string
	Features  []string
}

// Activate validates a license key, records the domain activation, and returns
// a signed Ed25519 token that the plugin caches locally (30-day TTL).
//
// Calling Activate with the same key+domain pair is idempotent — it refreshes
// the token without consuming a new slot.
func (s *Service) Activate(ctx context.Context, req ActivateRequest) (*ActivateResponse, error) {
	domain := NormalizeDomain(req.Domain)

	var resp *ActivateResponse

	err := s.db.WithTx(ctx, func(ctx context.Context, q db.DBTX) error {
		lic, count, existing, err := db.LockAndGetLicenseForActivation(ctx, q, req.Key, domain)
		if err != nil {
			if errors.Is(err, db.ErrNotFound) {
				return ErrKeyNotFound
			}
			return fmt.Errorf("lock license: %w", err)
		}

		// Status check.
		switch lic.Status {
		case "active":
			// ok
		case "expired":
			return ErrKeyExpired
		default:
			return ErrKeyRevoked
		}

		// Expiry check (backend-side — the token also carries exp).
		if lic.ExpiresAt != nil && time.Now().After(*lic.ExpiresAt) {
			_ = db.UpdateLicenseStatus(ctx, q, lic.ID, "expired")
			return ErrKeyExpired
		}

		// Product check.
		if lic.ProductSlug != req.Product {
			return ErrProductMismatch
		}

		// Slot check — skip if domain is already activated (idempotent refresh).
		if !existing && lic.MaxActivations != -1 && count >= lic.MaxActivations {
			return ErrMaxActivationsReached
		}

		if err := db.UpsertActivation(ctx, q, lic.ID, domain); err != nil {
			return fmt.Errorf("upsert activation: %w", err)
		}

		_ = db.InsertLicenseEvent(ctx, q, lic.ID, "activated", map[string]any{
			"domain":         domain,
			"plugin_version": req.PluginVersion,
			"idempotent":     existing,
		})

		// Issue signed token.
		features := TierFeatures[lic.Tier]
		if features == nil {
			features = []string{"offload"} // safe minimum for unknown tiers
		}
		expiresAt := time.Now().Add(s.tokenTTL)
		claims := TokenClaims{
			LicenseID:  lic.ID,
			Domain:     domain,
			Product:    req.Product,
			Tier:       lic.Tier,
			Features:   features,
			IssuedAt:   time.Now().Unix(),
			ExpiresAt:  expiresAt.Unix(),
			GraceUntil: expiresAt.Add(s.gracePeriod).Unix(),
		}
		token, err := SignToken(claims, s.privateKey)
		if err != nil {
			return fmt.Errorf("sign token: %w", err)
		}

		resp = &ActivateResponse{
			Token:     token,
			ExpiresAt: expiresAt,
			Tier:      lic.Tier,
			Features:  features,
		}
		return nil
	})

	return resp, err
}

// ── Deactivate ────────────────────────────────────────────────────────────────

// Deactivate removes a domain from the active activation list for a license key.
func (s *Service) Deactivate(ctx context.Context, key, domain string) error {
	domain = NormalizeDomain(domain)

	lic, err := db.GetLicenseByKey(ctx, s.db.Pool(), key)
	if err != nil {
		if errors.Is(err, db.ErrNotFound) {
			return ErrKeyNotFound
		}
		return fmt.Errorf("get license: %w", err)
	}

	if err := db.DeleteActivation(ctx, s.db.Pool(), lic.ID, domain); err != nil {
		return fmt.Errorf("delete activation: %w", err)
	}

	_ = db.InsertLicenseEvent(ctx, s.db.Pool(), lic.ID, "deactivated", map[string]any{
		"domain": domain,
	})
	return nil
}

// DeactivateByID removes a domain from the active activation list for a license
// identified by its UUID. Used by token-authenticated deactivation requests
// (the activation token embeds the license UUID instead of the raw key).
func (s *Service) DeactivateByID(ctx context.Context, licenseID, domain string) error {
	domain = NormalizeDomain(domain)

	if err := db.DeleteActivationByLicenseID(ctx, s.db.Pool(), licenseID, domain); err != nil {
		return fmt.Errorf("delete activation: %w", err)
	}

	_ = db.InsertLicenseEvent(ctx, s.db.Pool(), licenseID, "deactivated", map[string]any{
		"domain": domain,
		"via":    "token_auth",
	})
	return nil
}

// ListActivationsByID returns all currently active domains for a license UUID.
// Used by token-authenticated activations-list requests.
func (s *Service) ListActivationsByID(ctx context.Context, licenseID string) (*ListActivationsResponse, error) {
	lic, err := db.GetLicenseByID(ctx, s.db.Pool(), licenseID)
	if err != nil {
		if errors.Is(err, db.ErrNotFound) {
			return nil, ErrKeyNotFound
		}
		return nil, fmt.Errorf("get license: %w", err)
	}

	activations, err := db.ListActivationsByLicenseID(ctx, s.db.Pool(), licenseID)
	if err != nil {
		return nil, fmt.Errorf("list activations: %w", err)
	}

	return &ListActivationsResponse{
		Tier:           lic.Tier,
		MaxActivations: lic.MaxActivations,
		Activations:    activations,
	}, nil
}

// ── Status ────────────────────────────────────────────────────────────────────

// StatusResponse describes the current state of a license key.
type StatusResponse struct {
	Status         string
	Tier           string
	MaxActivations int
	ActiveDomains  []string
	ExpiresAt      *time.Time
}

// Status returns the state of a license key without issuing a new token.
// The key parameter is kept for compatibility with admin tooling.
func (s *Service) Status(ctx context.Context, licenseID string) (*StatusResponse, error) {
	lic, err := db.GetLicenseByID(ctx, s.db.Pool(), licenseID)
	if err != nil {
		if errors.Is(err, db.ErrNotFound) {
			return nil, ErrKeyNotFound
		}
		return nil, fmt.Errorf("get license: %w", err)
	}

	domains, err := db.ListActivationDomains(ctx, s.db.Pool(), lic.ID)
	if err != nil {
		return nil, fmt.Errorf("list activations: %w", err)
	}

	return &StatusResponse{
		Status:         lic.Status,
		Tier:           lic.Tier,
		MaxActivations: lic.MaxActivations,
		ActiveDomains:  domains,
		ExpiresAt:      lic.ExpiresAt,
	}, nil
}

// ── IssueLicense ──────────────────────────────────────────────────────────────

// tierActivations maps a tier name to the maximum number of domain activations.
var tierActivations = map[string]int{
	"free":     1,
	"personal": 1,
	"pro":      5,
	"agency":   -1, // unlimited
}

// Sentinel errors specific to license issuance.
var (
	ErrUnknownTier     = errors.New("unknown license tier")
	ErrProductNotFound = errors.New("product not found")
)

// IssueLicenseRequest is the input for creating a new license after a purchase.
type IssueLicenseRequest struct {
	Email          string
	Tier           string
	ProductSlug    string
	PaymentID      string // stored in audit log only
	OrderID        string // stored in audit log only (one-time payments)
	SubscriptionID string // Razorpay subscription ID (empty for one-time payments)
	BillingCycle   string // "monthly" | "annual" (empty = "monthly")
}

// IssueLicenseResponse carries the newly created license key.
type IssueLicenseResponse struct {
	Key   string
	Tier  string
	Email string
}

// IssueLicense upserts a customer by email, looks up the product by slug, generates
// a new license key, persists the license, and records a purchase audit event —
// all inside a single transaction.
func (s *Service) IssueLicense(ctx context.Context, req IssueLicenseRequest) (*IssueLicenseResponse, error) {
	maxActivations, ok := tierActivations[req.Tier]
	if !ok {
		return nil, ErrUnknownTier
	}

	billingCycle := req.BillingCycle
	if billingCycle == "" {
		billingCycle = "monthly"
	}

	var resp *IssueLicenseResponse

	err := s.db.WithTx(ctx, func(ctx context.Context, q db.DBTX) error {
		prod, err := db.GetProductBySlug(ctx, q, req.ProductSlug)
		if err != nil {
			if errors.Is(err, db.ErrNotFound) {
				return ErrProductNotFound
			}
			return fmt.Errorf("get product: %w", err)
		}

		customer, err := db.UpsertCustomer(ctx, q, req.Email)
		if err != nil {
			return fmt.Errorf("upsert customer: %w", err)
		}

		key, err := GenerateKey("nmb_live")
		if err != nil {
			return fmt.Errorf("generate key: %w", err)
		}

		var subIDPtr *string
		if req.SubscriptionID != "" {
			subIDPtr = &req.SubscriptionID
		}

		lic, err := db.CreateLicense(ctx, q, db.CreateLicenseParams{
			ProductID:      prod.ID,
			CustomerID:     customer.ID,
			Key:            key,
			Tier:           req.Tier,
			MaxActivations: maxActivations,
			SubscriptionID: subIDPtr,
			BillingCycle:   billingCycle,
			ExpiresAt:      nil, // perpetual until subscription cancelled
		})
		if err != nil {
			return fmt.Errorf("create license: %w", err)
		}

		// If issued via subscription, link the subscription record.
		if req.SubscriptionID != "" {
			if linkErr := db.LinkSubscriptionToLicense(ctx, q, req.SubscriptionID, lic.ID); linkErr != nil {
				// Non-fatal: the license exists, subscription link can be repaired by webhook.
				_ = linkErr
			}
		}

		_ = db.InsertLicenseEvent(ctx, q, lic.ID, "purchased", map[string]any{
			"payment_id":      req.PaymentID,
			"order_id":        req.OrderID,
			"subscription_id": req.SubscriptionID,
			"billing_cycle":   billingCycle,
			"tier":            req.Tier,
		})

		resp = &IssueLicenseResponse{Key: lic.Key, Tier: lic.Tier, Email: customer.Email}
		return nil
	})

	return resp, err
}

// ── ListActivations ───────────────────────────────────────────────────────────

// ListActivationsResponse holds the activated domains for a license key.
type ListActivationsResponse struct {
	Tier           string
	MaxActivations int
	Activations    []db.ActivationSummary
}

// ListActivations returns all currently active domains for a license key.
// Kept for compatibility with the admin CLI tool.
func (s *Service) ListActivations(ctx context.Context, key string) (*ListActivationsResponse, error) {
	lic, err := db.GetLicenseByKey(ctx, s.db.Pool(), key)
	if err != nil {
		if errors.Is(err, db.ErrNotFound) {
			return nil, ErrKeyNotFound
		}
		return nil, fmt.Errorf("get license: %w", err)
	}

	activations, err := db.ListActivationsByKey(ctx, s.db.Pool(), key)
	if err != nil {
		return nil, fmt.Errorf("list activations: %w", err)
	}

	return &ListActivationsResponse{
		Tier:           lic.Tier,
		MaxActivations: lic.MaxActivations,
		Activations:    activations,
	}, nil
}

// ── RegisterFree ──────────────────────────────────────────────────────────────

// RegisterFree looks up or creates a free-tier license for the given email, then
// activates it for the given domain. The caller never sees a license key — the
// token is returned directly. Subsequent calls with the same email+domain are
// idempotent (returns a fresh token each time).
func (s *Service) RegisterFree(ctx context.Context, email, domain, productSlug string) (*ActivateResponse, error) {
	key, err := s.getOrCreateFreeLicenseKey(ctx, email, productSlug)
	if err != nil {
		return nil, err
	}
	return s.Activate(ctx, ActivateRequest{
		Key:     key,
		Domain:  domain,
		Product: productSlug,
	})
}

func (s *Service) getOrCreateFreeLicenseKey(ctx context.Context, email, productSlug string) (string, error) {
	var key string

	err := s.db.WithTx(ctx, func(ctx context.Context, q db.DBTX) error {
		cust, err := db.UpsertCustomer(ctx, q, email)
		if err != nil {
			return fmt.Errorf("upsert customer: %w", err)
		}

		lic, err := db.GetFreeLicenseForCustomer(ctx, q, cust.ID, productSlug)
		if err == nil {
			key = lic.Key
			return nil
		}
		if !errors.Is(err, db.ErrNotFound) {
			return fmt.Errorf("get free license: %w", err)
		}

		// No free license yet — create one.
		prod, err := db.GetProductBySlug(ctx, q, productSlug)
		if err != nil {
			if errors.Is(err, db.ErrNotFound) {
				return ErrProductNotFound
			}
			return fmt.Errorf("get product: %w", err)
		}

		newKey, err := GenerateKey("nmb_free")
		if err != nil {
			return fmt.Errorf("generate key: %w", err)
		}

		lic2, err := db.CreateLicense(ctx, q, db.CreateLicenseParams{
			ProductID:      prod.ID,
			CustomerID:     cust.ID,
			Key:            newKey,
			Tier:           "free",
			MaxActivations: 1,
			BillingCycle:   "free",
			ExpiresAt:      nil, // never expires
		})
		if err != nil {
			return fmt.Errorf("create free license: %w", err)
		}

		_ = db.InsertLicenseEvent(ctx, q, lic2.ID, "registered_free", map[string]any{
			"email": email,
		})

		key = lic2.Key
		return nil
	})

	return key, err
}
