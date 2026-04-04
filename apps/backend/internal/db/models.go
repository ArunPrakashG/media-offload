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
	ExpiresAt      *time.Time // nil = never expires
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
	ExpiresAt      *time.Time
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
