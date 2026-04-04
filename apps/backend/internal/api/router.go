package api

import (
	"log/slog"
	"net/http"

	"github.com/go-chi/chi/v5"
	chimiddleware "github.com/go-chi/chi/v5/middleware"
	"github.com/redis/go-redis/v9"

	licenseshandler "github.com/arunprakashg/nimbus-backend/internal/api/licenses"
	"github.com/arunprakashg/nimbus-backend/internal/api/middleware"
	"github.com/arunprakashg/nimbus-backend/internal/license"
)

// NewRouter builds the chi router with all routes and middleware wired up.
func NewRouter(svc *license.Service, rdb *redis.Client, logger *slog.Logger) http.Handler {
	r := chi.NewRouter()

	r.Use(middleware.Logger(logger))
	r.Use(chimiddleware.Recoverer)
	r.Use(chimiddleware.StripSlashes)

	h := licenseshandler.New(svc, rdb)

	r.Route("/v1/licenses", func(r chi.Router) {
		r.Post("/activate", h.Activate)
		r.Post("/deactivate", h.Deactivate)
		r.Get("/status", h.Status)
	})

	// Health probe (used by Docker and load balancers).
	r.Get("/health", func(w http.ResponseWriter, r *http.Request) {
		JSON(w, http.StatusOK, map[string]string{"status": "ok"})
	})

	return r
}
