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
var TierFeatures = map[string][]string{
	"personal": {"offload"},
	"pro":      {"offload", "cdn", "multisite"},
	"agency":   {"offload", "cdn", "multisite", "priority_support"},
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
	db         *db.DB
	privateKey ed25519.PrivateKey
	publicKey  ed25519.PublicKey
	tokenTTL   time.Duration
}

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
		db:         database,
		privateKey: ed25519.PrivateKey(privBytes),
		publicKey:  ed25519.PublicKey(pubBytes),
		tokenTTL:   time.Duration(cfg.TokenTTLDays) * 24 * time.Hour,
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
			features = TierFeatures["personal"]
		}
		expiresAt := time.Now().Add(s.tokenTTL)
		claims := TokenClaims{
			Domain:    domain,
			Product:   req.Product,
			Tier:      lic.Tier,
			Features:  features,
			IssuedAt:  time.Now().Unix(),
			ExpiresAt: expiresAt.Unix(),
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
func (s *Service) Status(ctx context.Context, key string) (*StatusResponse, error) {
	lic, err := db.GetLicenseByKey(ctx, s.db.Pool(), key)
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
