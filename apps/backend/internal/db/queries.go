package db

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"time"

	"github.com/jackc/pgx/v5"
)

// ErrNotFound is returned when a queried record does not exist.
var ErrNotFound = errors.New("record not found")

// ── Activation flow ───────────────────────────────────────────────────────────

// LockAndGetLicenseForActivation fetches the license row with a FOR UPDATE lock
// (must be called inside a transaction), and also returns:
//   - the current total activation count for the license
//   - whether the specific domain is already activated
func LockAndGetLicenseForActivation(
	ctx context.Context, q DBTX, key, domain string,
) (*LicenseForActivation, int, bool, error) {
	const sql = `
		SELECT
			l.id::text,
			p.slug,
			l.tier,
			l.status,
			l.max_activations,
			l.expires_at,
			(SELECT COUNT(*) FROM activations WHERE license_id = l.id)::int AS cnt,
			EXISTS(SELECT 1 FROM activations WHERE license_id = l.id AND domain = $2) AS existing
		FROM licenses l
		JOIN products p ON p.id = l.product_id
		WHERE l.key = $1
		FOR UPDATE OF l`

	var lic LicenseForActivation
	var count int
	var existing bool
	var expiresAt *time.Time

	err := q.QueryRow(ctx, sql, key, domain).Scan(
		&lic.ID, &lic.ProductSlug, &lic.Tier, &lic.Status,
		&lic.MaxActivations, &expiresAt, &count, &existing,
	)
	if err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return nil, 0, false, ErrNotFound
		}
		return nil, 0, false, fmt.Errorf("scan license for activation: %w", err)
	}
	lic.ExpiresAt = expiresAt
	return &lic, count, existing, nil
}

// UpsertActivation inserts a new activation or refreshes timestamps if the
// domain is already active on this license (idempotent).
func UpsertActivation(ctx context.Context, q DBTX, licenseID, domain string) error {
	const sql = `
		INSERT INTO activations (license_id, domain)
		VALUES ($1::uuid, $2)
		ON CONFLICT (license_id, domain) DO UPDATE
		  SET last_seen_at = NOW(), token_issued_at = NOW()`
	_, err := q.Exec(ctx, sql, licenseID, domain)
	return err
}

// DeleteActivation removes a specific domain activation from a license.
func DeleteActivation(ctx context.Context, q DBTX, licenseID, domain string) error {
	_, err := q.Exec(ctx,
		`DELETE FROM activations WHERE license_id = $1::uuid AND domain = $2`,
		licenseID, domain)
	return err
}

// UpdateLicenseStatus sets the status field on a license by ID.
func UpdateLicenseStatus(ctx context.Context, q DBTX, id, status string) error {
	_, err := q.Exec(ctx,
		`UPDATE licenses SET status = $2 WHERE id = $1::uuid`,
		id, status)
	return err
}

// ── License lookups ───────────────────────────────────────────────────────────

// GetLicenseByKey returns a license by its raw key string.
func GetLicenseByKey(ctx context.Context, q DBTX, key string) (*License, error) {
	const sql = `
		SELECT id::text, product_id::text, customer_id::text,
		       key, tier, status, max_activations, expires_at, created_at
		FROM licenses WHERE key = $1`

	var l License
	var expiresAt *time.Time
	err := q.QueryRow(ctx, sql, key).Scan(
		&l.ID, &l.ProductID, &l.CustomerID,
		&l.Key, &l.Tier, &l.Status, &l.MaxActivations,
		&expiresAt, &l.CreatedAt,
	)
	if err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return nil, ErrNotFound
		}
		return nil, err
	}
	l.ExpiresAt = expiresAt
	return &l, nil
}

// GetLicenseByID returns a license by its UUID. Used by token-authenticated
// requests where the token carries the license ID rather than the raw key.
func GetLicenseByID(ctx context.Context, q DBTX, id string) (*License, error) {
	const sql = `
		SELECT id::text, product_id::text, customer_id::text,
		       key, tier, status, max_activations, expires_at, created_at
		FROM licenses WHERE id = $1::uuid`

	var l License
	var expiresAt *time.Time
	err := q.QueryRow(ctx, sql, id).Scan(
		&l.ID, &l.ProductID, &l.CustomerID,
		&l.Key, &l.Tier, &l.Status, &l.MaxActivations,
		&expiresAt, &l.CreatedAt,
	)
	if err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return nil, ErrNotFound
		}
		return nil, err
	}
	l.ExpiresAt = expiresAt
	return &l, nil
}

// ListActivationsByLicenseID returns the active domain summaries for a license UUID.
// Used by token-authenticated activations-list requests.
func ListActivationsByLicenseID(ctx context.Context, q DBTX, licenseID string) ([]ActivationSummary, error) {
	const sql = `
		SELECT domain, activated_at, last_seen_at
		FROM activations
		WHERE license_id = $1::uuid
		ORDER BY activated_at`

	rows, err := q.Query(ctx, sql, licenseID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []ActivationSummary
	for rows.Next() {
		var a ActivationSummary
		if err := rows.Scan(&a.Domain, &a.ActivatedAt, &a.LastSeenAt); err != nil {
			return nil, err
		}
		out = append(out, a)
	}
	return out, rows.Err()
}

// DeleteActivationByLicenseID removes a domain from a license identified by UUID.
// Used by token-authenticated deactivation requests.
func DeleteActivationByLicenseID(ctx context.Context, q DBTX, licenseID, domain string) error {
	_, err := q.Exec(ctx,
		`DELETE FROM activations WHERE license_id = $1::uuid AND domain = $2`,
		licenseID, domain)
	return err
}

// ListActivationDomains returns the active domain strings for a license.
func ListActivationDomains(ctx context.Context, q DBTX, licenseID string) ([]string, error) {
	rows, err := q.Query(ctx,
		`SELECT domain FROM activations WHERE license_id = $1::uuid ORDER BY activated_at`,
		licenseID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []string
	for rows.Next() {
		var d string
		if err := rows.Scan(&d); err != nil {
			return nil, err
		}
		out = append(out, d)
	}
	return out, rows.Err()
}

// ListActivations returns the full activation records for a given license key.
func ListActivations(ctx context.Context, q DBTX, key string) ([]Activation, error) {
	const sql = `
		SELECT a.id::text, a.license_id::text, a.domain,
		       a.activated_at, a.last_seen_at, a.token_issued_at
		FROM activations a
		JOIN licenses l ON l.id = a.license_id
		WHERE l.key = $1
		ORDER BY a.activated_at`

	rows, err := q.Query(ctx, sql, key)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []Activation
	for rows.Next() {
		var a Activation
		if err := rows.Scan(
			&a.ID, &a.LicenseID, &a.Domain,
			&a.ActivatedAt, &a.LastSeenAt, &a.TokenIssuedAt,
		); err != nil {
			return nil, err
		}
		out = append(out, a)
	}
	return out, rows.Err()
}

// ── Audit log ─────────────────────────────────────────────────────────────────

// InsertLicenseEvent appends an immutable audit event for a license.
func InsertLicenseEvent(ctx context.Context, q DBTX, licenseID, eventType string, metadata map[string]any) error {
	b, err := json.Marshal(metadata)
	if err != nil {
		return err
	}
	_, err = q.Exec(ctx,
		`INSERT INTO license_events (license_id, event_type, metadata) VALUES ($1::uuid, $2, $3)`,
		licenseID, eventType, string(b))
	return err
}

// ── Admin: license management ─────────────────────────────────────────────────

// CreateLicense inserts a new license and returns the full row.
func CreateLicense(ctx context.Context, q DBTX, p CreateLicenseParams) (*License, error) {
	billingCycle := p.BillingCycle
	if billingCycle == "" {
		billingCycle = "monthly"
	}
	const sql = `
		INSERT INTO licenses (product_id, customer_id, key, tier, max_activations,
		                      subscription_id, billing_cycle, expires_at)
		VALUES ($1::uuid, $2::uuid, $3, $4, $5, $6, $7, $8)
		RETURNING id::text, product_id::text, customer_id::text,
		          key, tier, status, max_activations,
		          subscription_id, billing_cycle, expires_at, created_at`

	var l License
	var expiresAt *time.Time
	err := q.QueryRow(ctx, sql,
		p.ProductID, p.CustomerID, p.Key, p.Tier, p.MaxActivations,
		p.SubscriptionID, billingCycle, p.ExpiresAt,
	).Scan(
		&l.ID, &l.ProductID, &l.CustomerID,
		&l.Key, &l.Tier, &l.Status, &l.MaxActivations,
		&l.SubscriptionID, &l.BillingCycle,
		&expiresAt, &l.CreatedAt,
	)
	if err != nil {
		return nil, err
	}
	l.ExpiresAt = expiresAt
	return &l, nil
}

// RevokeLicense sets a license status to 'revoked'. Returns ErrNotFound if the key doesn't exist.
func RevokeLicense(ctx context.Context, q DBTX, key string) error {
	tag, err := q.Exec(ctx,
		`UPDATE licenses SET status = 'revoked' WHERE key = $1 AND status != 'revoked'`,
		key)
	if err != nil {
		return err
	}
	if tag.RowsAffected() == 0 {
		return ErrNotFound
	}
	return nil
}

// ExtendLicense adds days to the expiry of a license (or sets expiry relative to NOW if currently NULL).
func ExtendLicense(ctx context.Context, q DBTX, key string, days int) error {
	const sql = `
		UPDATE licenses
		SET expires_at = COALESCE(expires_at, NOW()) + ($2 * INTERVAL '1 day')
		WHERE key = $1`
	tag, err := q.Exec(ctx, sql, key, days)
	if err != nil {
		return err
	}
	if tag.RowsAffected() == 0 {
		return ErrNotFound
	}
	return nil
}

// ListLicensesByEmail returns all licenses for a customer identified by email.
func ListLicensesByEmail(ctx context.Context, q DBTX, email string) ([]LicenseWithJoins, error) {
	const sql = `
		SELECT l.id::text, l.key, l.tier, l.status, l.max_activations,
		       l.expires_at, l.created_at, c.email, p.slug, p.name,
		       (SELECT COUNT(*) FROM activations WHERE license_id = l.id)::int
		FROM licenses l
		JOIN customers c ON c.id = l.customer_id
		JOIN products p ON p.id = l.product_id
		WHERE c.email = $1
		ORDER BY l.created_at DESC`
	return scanLicenseJoins(ctx, q, sql, email)
}

// ListAllLicenses returns every license in the system, newest first.
func ListAllLicenses(ctx context.Context, q DBTX) ([]LicenseWithJoins, error) {
	const sql = `
		SELECT l.id::text, l.key, l.tier, l.status, l.max_activations,
		       l.expires_at, l.created_at, c.email, p.slug, p.name,
		       (SELECT COUNT(*) FROM activations WHERE license_id = l.id)::int
		FROM licenses l
		JOIN customers c ON c.id = l.customer_id
		JOIN products p ON p.id = l.product_id
		ORDER BY l.created_at DESC`
	return scanLicenseJoins(ctx, q, sql)
}

func scanLicenseJoins(ctx context.Context, q DBTX, sql string, args ...any) ([]LicenseWithJoins, error) {
	rows, err := q.Query(ctx, sql, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []LicenseWithJoins
	for rows.Next() {
		var l LicenseWithJoins
		var expiresAt *time.Time
		if err := rows.Scan(
			&l.ID, &l.Key, &l.Tier, &l.Status, &l.MaxActivations,
			&expiresAt, &l.CreatedAt, &l.CustomerEmail, &l.ProductSlug,
			&l.ProductName, &l.ActiveDomains,
		); err != nil {
			return nil, err
		}
		l.ExpiresAt = expiresAt
		out = append(out, l)
	}
	return out, rows.Err()
}

// ── Admin: customers ──────────────────────────────────────────────────────────

// UpsertCustomer finds or creates a customer by email (case-insensitive).
func UpsertCustomer(ctx context.Context, q DBTX, email string) (*Customer, error) {
	const sql = `
		INSERT INTO customers (email) VALUES ($1)
		ON CONFLICT (email) DO UPDATE SET email = EXCLUDED.email
		RETURNING id::text, email, notes, created_at`
	var c Customer
	err := q.QueryRow(ctx, sql, email).Scan(&c.ID, &c.Email, &c.Notes, &c.CreatedAt)
	return &c, err
}

// ListCustomers returns all customers ordered by email.
func ListCustomers(ctx context.Context, q DBTX) ([]Customer, error) {
	rows, err := q.Query(ctx,
		`SELECT id::text, email, notes, created_at FROM customers ORDER BY email`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []Customer
	for rows.Next() {
		var c Customer
		if err := rows.Scan(&c.ID, &c.Email, &c.Notes, &c.CreatedAt); err != nil {
			return nil, err
		}
		out = append(out, c)
	}
	return out, rows.Err()
}

// ── Admin: products ───────────────────────────────────────────────────────────

// GetProductBySlug fetches a product by its slug.
func GetProductBySlug(ctx context.Context, q DBTX, slug string) (*Product, error) {
	var p Product
	err := q.QueryRow(ctx,
		`SELECT id::text, slug, name, created_at FROM products WHERE slug = $1`,
		slug,
	).Scan(&p.ID, &p.Slug, &p.Name, &p.CreatedAt)
	if err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return nil, ErrNotFound
		}
		return nil, err
	}
	return &p, nil
}

// CreateProduct inserts a new product.
func CreateProduct(ctx context.Context, q DBTX, slug, name string) (*Product, error) {
	var p Product
	err := q.QueryRow(ctx,
		`INSERT INTO products (slug, name) VALUES ($1, $2) RETURNING id::text, slug, name, created_at`,
		slug, name,
	).Scan(&p.ID, &p.Slug, &p.Name, &p.CreatedAt)
	return &p, err
}

// ListProducts returns all products ordered by slug.
func ListProducts(ctx context.Context, q DBTX) ([]Product, error) {
	rows, err := q.Query(ctx,
		`SELECT id::text, slug, name, created_at FROM products ORDER BY slug`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []Product
	for rows.Next() {
		var p Product
		if err := rows.Scan(&p.ID, &p.Slug, &p.Name, &p.CreatedAt); err != nil {
			return nil, err
		}
		out = append(out, p)
	}
	return out, rows.Err()
}

// ── Activations list ──────────────────────────────────────────────────────────

// ActivationSummary is a slim view of an active domain on a license.
type ActivationSummary struct {
	Domain      string
	ActivatedAt time.Time
	LastSeenAt  time.Time
}

// ListActivationsByKey returns all currently active domains for a license key.
func ListActivationsByKey(ctx context.Context, q DBTX, key string) ([]ActivationSummary, error) {
	const sql = `
		SELECT a.domain, a.activated_at, a.last_seen_at
		FROM activations a
		JOIN licenses l ON l.id = a.license_id
		WHERE l.key = $1
		ORDER BY a.activated_at`

	rows, err := q.Query(ctx, sql, key)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []ActivationSummary
	for rows.Next() {
		var a ActivationSummary
		if err := rows.Scan(&a.Domain, &a.ActivatedAt, &a.LastSeenAt); err != nil {
			return nil, err
		}
		out = append(out, a)
	}
	return out, rows.Err()
}

// ── Razorpay subscriptions ────────────────────────────────────────────────────

// UpsertRazorpaySubscription inserts or updates a Razorpay subscription record.
// On conflict it refreshes all mutable fields.
func UpsertRazorpaySubscription(ctx context.Context, q DBTX, p UpsertRazorpaySubscriptionParams) (*RazorpaySubscription, error) {
	const sql = `
		INSERT INTO razorpay_subscriptions
		            (rzp_sub_id, plan_id, tier, billing_cycle, customer_email, status,
		             current_start, current_end)
		VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
		ON CONFLICT (rzp_sub_id) DO UPDATE
		    SET status        = EXCLUDED.status,
		        customer_email = CASE WHEN EXCLUDED.customer_email != '' THEN EXCLUDED.customer_email
		                              ELSE razorpay_subscriptions.customer_email END,
		        current_start = COALESCE(EXCLUDED.current_start, razorpay_subscriptions.current_start),
		        current_end   = COALESCE(EXCLUDED.current_end,   razorpay_subscriptions.current_end),
		        updated_at    = NOW()
		RETURNING id::text, license_id::text, rzp_sub_id, plan_id, tier, billing_cycle,
		          customer_email, status, current_start, current_end, created_at, updated_at`

	var s RazorpaySubscription
	err := q.QueryRow(ctx, sql,
		p.RzpSubID, p.PlanID, p.Tier, p.BillingCycle, p.CustomerEmail,
		p.Status, p.CurrentStart, p.CurrentEnd,
	).Scan(
		&s.ID, &s.LicenseID, &s.RzpSubID, &s.PlanID, &s.Tier, &s.BillingCycle,
		&s.CustomerEmail, &s.Status, &s.CurrentStart, &s.CurrentEnd,
		&s.CreatedAt, &s.UpdatedAt,
	)
	return &s, err
}

// LinkSubscriptionToLicense sets the license_id on a razorpay_subscriptions row
// and sets the subscription_id on the license row — used after license issuance.
func LinkSubscriptionToLicense(ctx context.Context, q DBTX, rzpSubID, licenseID string) error {
	_, err := q.Exec(ctx,
		`UPDATE razorpay_subscriptions SET license_id = $2::uuid, updated_at = NOW() WHERE rzp_sub_id = $1`,
		rzpSubID, licenseID)
	if err != nil {
		return err
	}
	_, err = q.Exec(ctx,
		`UPDATE licenses SET subscription_id = $2 WHERE id = $1::uuid`,
		licenseID, rzpSubID)
	return err
}

// GetLicenseBySubscriptionID looks up a license by its Razorpay subscription ID.
func GetLicenseBySubscriptionID(ctx context.Context, q DBTX, rzpSubID string) (*License, error) {
	const sql = `
		SELECT id::text, product_id::text, customer_id::text,
		       key, tier, status, max_activations,
		       subscription_id, billing_cycle, expires_at, created_at
		FROM licenses WHERE subscription_id = $1`

	var l License
	var expiresAt *time.Time
	err := q.QueryRow(ctx, sql, rzpSubID).Scan(
		&l.ID, &l.ProductID, &l.CustomerID,
		&l.Key, &l.Tier, &l.Status, &l.MaxActivations,
		&l.SubscriptionID, &l.BillingCycle,
		&expiresAt, &l.CreatedAt,
	)
	if err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return nil, ErrNotFound
		}
		return nil, err
	}
	l.ExpiresAt = expiresAt
	return &l, nil
}

// UpdateLicenseExpiry sets the expires_at on a license (used on subscription renewal).
func UpdateLicenseExpiry(ctx context.Context, q DBTX, licenseID string, expiresAt *time.Time) error {
	_, err := q.Exec(ctx,
		`UPDATE licenses SET expires_at = $2 WHERE id = $1::uuid`,
		licenseID, expiresAt)
	return err
}

// ── Plugin releases ───────────────────────────────────────────────────────────

// GetLatestRelease returns the most recently published release for a product slug.
// Returns ErrNotFound if no release has been registered yet.
func GetLatestRelease(ctx context.Context, q DBTX, productSlug string) (*PluginRelease, error) {
	const sql = `
                SELECT r.id::text, r.version, r.download_url, r.changelog,
                       r.min_wp_version, r.min_php_version, r.tested_up_to, r.released_at
                FROM plugin_releases r
                JOIN products p ON p.id = r.product_id
                WHERE p.slug = $1
                ORDER BY r.released_at DESC
                LIMIT 1`

	var rel PluginRelease
	err := q.QueryRow(ctx, sql, productSlug).Scan(
		&rel.ID, &rel.Version, &rel.DownloadURL, &rel.Changelog,
		&rel.MinWPVersion, &rel.MinPHPVersion, &rel.TestedUpTo, &rel.ReleasedAt,
	)
	if err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return nil, ErrNotFound
		}
		return nil, fmt.Errorf("get latest release: %w", err)
	}
	return &rel, nil
}

// CreateRelease inserts a new plugin release. Returns an error if the
// (product_id, version) pair already exists.
func CreateRelease(ctx context.Context, q DBTX, p CreateReleaseParams) (*PluginRelease, error) {
	const sql = `
                INSERT INTO plugin_releases
                            (product_id, version, download_url, changelog,
                             min_wp_version, min_php_version, tested_up_to)
                VALUES ($1::uuid, $2, $3, $4, $5, $6, $7)
                RETURNING id::text, version, download_url, changelog,
                          min_wp_version, min_php_version, tested_up_to, released_at`

	var rel PluginRelease
	err := q.QueryRow(ctx, sql,
		p.ProductID, p.Version, p.DownloadURL, p.Changelog,
		p.MinWPVersion, p.MinPHPVersion, p.TestedUpTo,
	).Scan(
		&rel.ID, &rel.Version, &rel.DownloadURL, &rel.Changelog,
		&rel.MinWPVersion, &rel.MinPHPVersion, &rel.TestedUpTo, &rel.ReleasedAt,
	)
	if err != nil {
		return nil, fmt.Errorf("create release: %w", err)
	}
	return &rel, nil
}

// GetFreeLicenseForCustomer returns the active free-tier license for a customer+product,
// or ErrNotFound if none exists.
func GetFreeLicenseForCustomer(ctx context.Context, q DBTX, customerID, productSlug string) (*License, error) {
	const sql = `
		SELECT l.id::text, l.customer_id::text, l.product_id::text,
		       l.key, l.tier, l.status, l.max_activations,
		       l.billing_cycle, l.expires_at, l.created_at
		FROM   licenses l
		JOIN   products p ON p.id = l.product_id
		WHERE  l.customer_id = $1::uuid
		  AND  p.slug        = $2
		  AND  l.tier        = 'free'
		  AND  l.status      = 'active'
		LIMIT  1`

	var lic License
	err := q.QueryRow(ctx, sql, customerID, productSlug).Scan(
		&lic.ID, &lic.CustomerID, &lic.ProductID,
		&lic.Key, &lic.Tier, &lic.Status, &lic.MaxActivations,
		&lic.BillingCycle, &lic.ExpiresAt, &lic.CreatedAt,
	)
	if err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return nil, ErrNotFound
		}
		return nil, fmt.Errorf("get free license: %w", err)
	}
	return &lic, nil
}
