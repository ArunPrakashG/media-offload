package config

import (
	"fmt"
	"os"
	"strconv"
)

// Config holds all runtime configuration loaded from environment variables.
type Config struct {
	Port              string
	DatabaseURL       string
	RedisURL          string
	LicensePrivateKey string // base64-encoded 64-byte Ed25519 private key
	LicensePublicKey  string // base64-encoded 32-byte Ed25519 public key
	TokenTTLDays      int
	// TokenGracePeriodDays is added to exp to compute the gau (grace-until) claim
	// embedded in every activation token. The plugin treats gau as the hard
	// deadline — features stop working after gau, not after exp.
	// Default: 7 days. Override via TOKEN_GRACE_PERIOD_DAYS env var.
	TokenGracePeriodDays int
	// AdminAPISecret protects the /admin/* routes. Required for license issuance.
	AdminAPISecret string
	// ProductSlug is the slug of the product in the database (default: "nimbus").
	ProductSlug string

	// R2 — Cloudflare R2 bucket used to distribute the pro plugin zip.
	// All fields are optional; if unset, the update check endpoint returns
	// update_available=false with reason="r2_not_configured".
	R2AccountID       string
	R2AccessKeyID     string
	R2AccessKeySecret string
	R2BucketName      string
	R2PresignTTLHours int // default 24

	// Razorpay — credentials for subscription creation and webhook verification.
	RazorpayKeyID         string
	RazorpayKeySecret     string
	RazorpayWebhookSecret string

	// Razorpay plan IDs — created once in the Razorpay dashboard.
	// Map: "<tier>_<billing_cycle>" → plan_id
	// e.g. RazorpayPlans["personal_monthly"] = "plan_xxx"
	RazorpayPlans map[string]string
}

// Load reads configuration from environment variables.
// Returns an error if any required variable is missing or invalid.
func Load() (*Config, error) {
	dbURL, err := require("DATABASE_URL")
	if err != nil {
		return nil, err
	}
	redisURL, err := require("REDIS_URL")
	if err != nil {
		return nil, err
	}
	privKey, err := require("LICENSE_PRIVATE_KEY")
	if err != nil {
		return nil, err
	}
	pubKey, err := require("LICENSE_PUBLIC_KEY")
	if err != nil {
		return nil, err
	}

	ttlDays := 30
	if v := os.Getenv("TOKEN_TTL_DAYS"); v != "" {
		ttlDays, err = strconv.Atoi(v)
		if err != nil {
			return nil, fmt.Errorf("invalid TOKEN_TTL_DAYS: %w", err)
		}
	}

	graceDays := 7
	if v := os.Getenv("TOKEN_GRACE_PERIOD_DAYS"); v != "" {
		graceDays, err = strconv.Atoi(v)
		if err != nil {
			return nil, fmt.Errorf("invalid TOKEN_GRACE_PERIOD_DAYS: %w", err)
		}
	}

	return &Config{
		Port:                  getOr("PORT", "8090"),
		DatabaseURL:           dbURL,
		RedisURL:              redisURL,
		LicensePrivateKey:     privKey,
		LicensePublicKey:      pubKey,
		TokenTTLDays:          ttlDays,
		TokenGracePeriodDays:  graceDays,
		AdminAPISecret:        os.Getenv("ADMIN_API_SECRET"),
		ProductSlug:           getOr("PRODUCT_SLUG", "nimbus"),

		R2AccountID:       os.Getenv("R2_ACCOUNT_ID"),
		R2AccessKeyID:     os.Getenv("R2_ACCESS_KEY_ID"),
		R2AccessKeySecret: os.Getenv("R2_ACCESS_KEY_SECRET"),
		R2BucketName:      os.Getenv("R2_BUCKET_NAME"),
		R2PresignTTLHours: func() int {
			if v := os.Getenv("R2_PRESIGN_TTL_HOURS"); v != "" {
				if n, err2 := strconv.Atoi(v); err2 == nil && n > 0 {
					return n
				}
			}
			return 24
		}(),

		RazorpayKeyID:         os.Getenv("RAZORPAY_KEY_ID"),
		RazorpayKeySecret:     os.Getenv("RAZORPAY_KEY_SECRET"),
		RazorpayWebhookSecret: os.Getenv("RAZORPAY_WEBHOOK_SECRET"),
		RazorpayPlans: map[string]string{
			"personal_annual": os.Getenv("RAZORPAY_PLAN_PERSONAL_ANNUAL"),
			"pro_annual":      os.Getenv("RAZORPAY_PLAN_PRO_ANNUAL"),
			"agency_annual":   os.Getenv("RAZORPAY_PLAN_AGENCY_ANNUAL"),
		},
	}, nil
}

func require(key string) (string, error) {
	v := os.Getenv(key)
	if v == "" {
		return "", fmt.Errorf("required environment variable %s is not set", key)
	}
	return v, nil
}

func getOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
