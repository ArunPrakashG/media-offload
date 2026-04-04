package api

import (
	"log/slog"
	"net/http"

	"github.com/go-chi/chi/v5"
	chimiddleware "github.com/go-chi/chi/v5/middleware"
	"github.com/redis/go-redis/v9"

	adminhandler "github.com/arunprakashg/nimbus-backend/internal/api/admin"
	checkouthandler "github.com/arunprakashg/nimbus-backend/internal/api/checkout"
	licenseshandler "github.com/arunprakashg/nimbus-backend/internal/api/licenses"
	"github.com/arunprakashg/nimbus-backend/internal/api/middleware"
	updateshandler "github.com/arunprakashg/nimbus-backend/internal/api/updates"
	webhookshandler "github.com/arunprakashg/nimbus-backend/internal/api/webhooks"
	"github.com/arunprakashg/nimbus-backend/internal/config"
	"github.com/arunprakashg/nimbus-backend/internal/db"
	"github.com/arunprakashg/nimbus-backend/internal/license"
)

// NewRouter builds the chi router with all routes and middleware wired up.
func NewRouter(
	cfg *config.Config,
	svc *license.Service,
	database *db.DB,
	rdb *redis.Client,
	logger *slog.Logger,
) http.Handler {
	r := chi.NewRouter()

	r.Use(middleware.Logger(logger))
	r.Use(chimiddleware.Recoverer)
	r.Use(chimiddleware.StripSlashes)

	h := licenseshandler.New(svc, rdb, cfg.ProductSlug)

	r.Route("/v1/licenses", func(r chi.Router) {
		// Register free tier — public, rate-limited by IP.
		r.Post("/register-free", h.RegisterFree)

		// Activate: public endpoint — caller proves key possession via HMAC signature.
		r.Post("/activate", h.Activate)

		// Deactivate, Status, Activations: require a verified Ed25519 activation token.
		// The token is issued on successful activation and embeds the license UUID so
		// the raw key is never sent for post-activation operations.
		r.Group(func(r chi.Router) {
			r.Use(middleware.RequireActivationToken(svc.PublicKey()))
			r.Post("/deactivate", h.Deactivate)
			r.Get("/status", h.Status)
			r.Get("/activations", h.Activations)
		})
	})

	// Admin routes — protected by a static Bearer token.
	// These are internal-only and should not be exposed to the public internet.
	ah := adminhandler.New(svc, database, cfg.ProductSlug)
	r.Route("/admin/v1", func(r chi.Router) {
		r.Use(middleware.AdminAuth(cfg.AdminAPISecret))
		r.Post("/licenses/issue", ah.IssueLicense)
		r.Post("/releases", ah.CreateRelease)
	})

	// Plugin update check — requires a valid activation token (Bearer auth).
	uh := updateshandler.New(database, cfg.ProductSlug)
	r.Route("/v1/updates", func(r chi.Router) {
		r.Use(middleware.RequireActivationToken(svc.PublicKey()))
		r.Get("/check", uh.Check)
	})

	// Razorpay webhook — signature-verified, no Bearer auth.
	wh := webhookshandler.New(cfg, svc, database)
	r.Post("/webhooks/razorpay", wh.HandleRazorpay)

	// Checkout — public endpoints used by the marketing site.
	// Razorpay credentials live here; the frontend only holds BACKEND_URL.
	ch := checkouthandler.New(cfg, svc)
	r.Route("/v1/checkout", func(r chi.Router) {
		r.Post("/create-subscription", ch.CreateSubscription)
		r.Post("/verify-payment", ch.VerifyPayment)
	})

	// Health probe (used by Docker and load balancers).
	r.Get("/health", func(w http.ResponseWriter, r *http.Request) {
		JSON(w, http.StatusOK, map[string]string{"status": "ok"})
	})

	return r
}
