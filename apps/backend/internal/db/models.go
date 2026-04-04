package db

import "time"

// Product is a software product served by this licensing backend.
// e.g. slug="nimbus", name="Nimbus"
type Product struct {
	ID        string
	Slug      string
	Name      string
	CreatedAt time.Time
}

// Customer is a person who has purchased one or more licenses.
type Customer struct {
	ID        string
	Email     string
	Notes     string
	CreatedAt time.Time
}

// License represents a single purchased license key.
type License struct {
	ID             string
	ProductID      string
	CustomerID     string
	Key            string
	Tier           string     // "personal" | "pro" | "agency"
	Status         string     // "active" | "expired" | "revoked" | "suspended"
	MaxActivations int        // -1 = unlimited
	SubscriptionID *string    // Razorpay subscription ID (nil for one-time/legacy)
	BillingCycle   string     // "monthly" | "annual"
	ExpiresAt      *time.Time // nil = never expires (perpetual)
	CreatedAt      time.Time
}

// Activation is a domain that has consumed an activation slot on a license.
type Activation struct {
	ID             string
	LicenseID      string
	Domain         string
	ActivatedAt    time.Time
	LastSeenAt     time.Time
	TokenIssuedAt  time.Time
}

// LicenseWithJoins is a projection for admin list views, joining license, customer, product.
type LicenseWithJoins struct {
	ID             string
	Key            string
	Tier           string
	Status         string
	MaxActivations int
	ExpiresAt      *time.Time
	CreatedAt      time.Time
	CustomerEmail  string
	ProductSlug    string
	ProductName    string
	ActiveDomains  int
}

// CreateLicenseParams contains the fields needed to issue a new license.
type CreateLicenseParams struct {
	ProductID      string
	CustomerID     string
	Key            string
	Tier           string
	MaxActivations int
	SubscriptionID *string
	BillingCycle   string
	ExpiresAt      *time.Time
}

// RazorpaySubscription tracks a Razorpay subscription linked to a license.
type RazorpaySubscription struct {
	ID            string
	LicenseID     *string
	RzpSubID      string
	PlanID        string
	Tier          string
	BillingCycle  string
	CustomerEmail string
	Status        string
	CurrentStart  *time.Time
	CurrentEnd    *time.Time
	CreatedAt     time.Time
	UpdatedAt     time.Time
}

// UpsertRazorpaySubscriptionParams is used to create or update a subscription record.
type UpsertRazorpaySubscriptionParams struct {
	RzpSubID      string
	PlanID        string
	Tier          string
	BillingCycle  string
	CustomerEmail string
	Status        string
	CurrentStart  *time.Time
	CurrentEnd    *time.Time
}

// LicenseForActivation is a slim projection fetched and locked during activation.
type LicenseForActivation struct {
	ID             string
	ProductSlug    string
	Tier           string
	Status         string
	MaxActivations int
	ExpiresAt      *time.Time
}
// PluginRelease is a published version of a product plugin.
type PluginRelease struct {
        ID             string
        Version        string
        DownloadURL    string
        Changelog      string
        MinWPVersion   string
        MinPHPVersion  string
        TestedUpTo     string
        ReleasedAt     time.Time
}

// CreateReleaseParams holds the fields required to register a new plugin release.
type CreateReleaseParams struct {
        ProductID      string
        Version        string
        DownloadURL    string
        Changelog      string
        MinWPVersion   string
        MinPHPVersion  string
        TestedUpTo     string
}