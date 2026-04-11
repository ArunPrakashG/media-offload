// Package updates provides HTTP handlers for the plugin update check API.
//
// WordPress calls GET /v1/updates/check on its regular update cycle (or when
// the admin visits Dashboard → Updates). The plugin authenticates with its
// Ed25519 activation token (same Bearer auth used by /v1/licenses/status).
//
// Response contract:
//
//	update_available = true  → the plugin should present the update badge; WP
//	                           downloads the zip from download_url automatically.
//	update_available = false → the plugin is current or the license is inactive.
//	reason = "license_inactive" → surface a "renew to get updates" admin notice.
//	reason = "r2_not_configured" → R2 env vars not set; admin must configure them.
package updates

import (
	"context"
	"errors"
	"net/http"
	"strconv"
	"strings"

	"github.com/arunprakashg/nimbus-backend/internal/api/middleware"
	"github.com/arunprakashg/nimbus-backend/internal/apiutil"
	"github.com/arunprakashg/nimbus-backend/internal/db"
)

// r2Presigner is the minimal interface the Handler needs from the R2 client.
// Using an interface keeps the handler testable without a live R2 connection.
type r2Presigner interface {
	PresignGet(ctx context.Context, objectKey string) (string, error)
}

// Handler serves the plugin update API.
type Handler struct {
	database    *db.DB
	productSlug string
	r2          r2Presigner // nil when R2 is not configured
}

// New creates a Handler.
// Pass a nil r2Client when R2 env vars are not configured; the handler will
// return update_available=false with reason="r2_not_configured" in that case.
func New(database *db.DB, productSlug string, r2Client r2Presigner) *Handler {
	return &Handler{database: database, productSlug: productSlug, r2: r2Client}
}

// ── GET /v1/updates/check ─────────────────────────────────────────────────────

type checkResponse struct {
	UpdateAvailable bool   `json:"update_available"`
	Version         string `json:"version,omitempty"`
	DownloadURL     string `json:"download_url,omitempty"`
	Changelog       string `json:"changelog,omitempty"`
	MinWPVersion    string `json:"min_wp_version,omitempty"`
	MinPHPVersion   string `json:"min_php_version,omitempty"`
	TestedUpTo      string `json:"tested_up_to,omitempty"`
	Reason          string `json:"reason,omitempty"`
}

// Check returns whether an update is available for the calling plugin installation.
//
// Query params:
//
//	version — the current installed plugin version (e.g. "0.1.4")
//
// Auth: Bearer activation token (verified by RequireActivationToken middleware).
// The token carries the license UUID so we can do a live status check without
// the raw key — a user with an expired/revoked license gets no update.
func (h *Handler) Check(w http.ResponseWriter, r *http.Request) {
	claims := middleware.ClaimsFromContext(r.Context())
	if claims == nil {
		// Should not happen — middleware guards this route — but be explicit.
		apiutil.Error(w, http.StatusUnauthorized, "unauthorized", "missing token claims")
		return
	}

	// Fail fast if R2 is not configured — no point querying the DB.
	if h.r2 == nil {
		apiutil.JSON(w, http.StatusOK, checkResponse{
			UpdateAvailable: false,
			Reason:          "r2_not_configured",
		})
		return
	}

	currentVersion := strings.TrimSpace(r.URL.Query().Get("version"))

	// ── Live license status check ─────────────────────────────────────────────
	// The activation token has its own TTL (30 days) and may still be valid
	// even after the annual license has expired. We do a cheap DB lookup to
	// confirm the license is still active before serving update metadata.
	lic, err := db.GetLicenseByID(r.Context(), h.database.Pool(), claims.LicenseID)
	if err != nil {
		if errors.Is(err, db.ErrNotFound) {
			apiutil.JSON(w, http.StatusOK, checkResponse{
				UpdateAvailable: false,
				Reason:          "license_not_found",
			})
			return
		}
		apiutil.Error(w, http.StatusInternalServerError, "internal_error", "could not verify license")
		return
	}

	if lic.Status != "active" {
		apiutil.JSON(w, http.StatusOK, checkResponse{
			UpdateAvailable: false,
			Reason:          "license_inactive",
		})
		return
	}

	// ── Fetch latest release ──────────────────────────────────────────────────
	latest, err := db.GetLatestRelease(r.Context(), h.database.Pool(), h.productSlug)
	if err != nil {
		if errors.Is(err, db.ErrNotFound) {
			// No release registered yet — nothing to offer.
			apiutil.JSON(w, http.StatusOK, checkResponse{UpdateAvailable: false})
			return
		}
		apiutil.Error(w, http.StatusInternalServerError, "internal_error", "could not fetch release info")
		return
	}

	// ── Version comparison ────────────────────────────────────────────────────
	if currentVersion != "" && compareVersions(latest.Version, currentVersion) <= 0 {
		// Already on the latest version.
		apiutil.JSON(w, http.StatusOK, checkResponse{UpdateAvailable: false})
		return
	}

	// ── Generate presigned R2 download URL ───────────────────────────────────
	// The bucket is private; we sign a 24-hour GET URL so WordPress can
	// download the zip via its built-in update mechanism. Each update check
	// cycle (~12 h) produces a fresh URL.
	downloadURL, err := h.r2.PresignGet(r.Context(), latest.R2ObjectKey)
	if err != nil {
		apiutil.Error(w, http.StatusInternalServerError, "internal_error", "could not generate download URL")
		return
	}

	apiutil.JSON(w, http.StatusOK, checkResponse{
		UpdateAvailable: true,
		Version:         latest.Version,
		DownloadURL:     downloadURL,
		Changelog:       latest.Changelog,
		MinWPVersion:    latest.MinWPVersion,
		MinPHPVersion:   latest.MinPHPVersion,
		TestedUpTo:      latest.TestedUpTo,
	})
}

// ── Semver comparison ─────────────────────────────────────────────────────────

// compareVersions returns:
//
//	 1 if a is newer than b
//	 0 if equal
//	-1 if a is older than b
//
// Only major.minor.patch segments are considered; pre-release suffixes are
// ignored. Both "1.2.3" and "v1.2.3" are accepted.
func compareVersions(a, b string) int {
	parse := func(v string) [3]int {
		v = strings.TrimPrefix(v, "v")
		// Drop any pre-release suffix (e.g. "1.2.0-alpha").
		if i := strings.IndexAny(v, "-+"); i != -1 {
			v = v[:i]
		}
		parts := strings.SplitN(v, ".", 3)
		var segs [3]int
		for i, p := range parts {
			if i >= 3 {
				break
			}
			segs[i], _ = strconv.Atoi(p)
		}
		return segs
	}

	as, bs := parse(a), parse(b)
	for i := range as {
		if as[i] > bs[i] {
			return 1
		}
		if as[i] < bs[i] {
			return -1
		}
	}
	return 0
}
