package main

import (
	"context"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/joho/godotenv"
	"github.com/redis/go-redis/v9"

	"github.com/arunprakashg/nimbus-backend/internal/api"
	"github.com/arunprakashg/nimbus-backend/internal/config"
	"github.com/arunprakashg/nimbus-backend/internal/db"
	"github.com/arunprakashg/nimbus-backend/internal/license"
)

func main() {
	// Load .env in development (ignored if file doesn't exist).
	_ = godotenv.Load()

	logger := slog.New(slog.NewJSONHandler(os.Stdout, nil))
	slog.SetDefault(logger)

	cfg, err := config.Load()
	if err != nil {
		logger.Error("config error", "err", err)
		os.Exit(1)
	}

	ctx := context.Background()

	database, err := db.New(ctx, cfg.DatabaseURL)
	if err != nil {
		logger.Error("database error", "err", err)
		os.Exit(1)
	}
	defer database.Close()

	redisOpts, err := redis.ParseURL(cfg.RedisURL)
	if err != nil {
		logger.Error("redis url parse error", "err", err)
		os.Exit(1)
	}
	rdb := redis.NewClient(redisOpts)
	if err := rdb.Ping(ctx).Err(); err != nil {
		logger.Error("redis ping error", "err", err)
		os.Exit(1)
	}
	defer rdb.Close()

	svc, err := license.NewService(database, cfg)
	if err != nil {
		logger.Error("license service error", "err", err)
		os.Exit(1)
	}

	router := api.NewRouter(cfg, svc, database, rdb, logger)

	srv := &http.Server{
		Addr:         ":" + cfg.Port,
		Handler:      router,
		ReadTimeout:  10 * time.Second,
		WriteTimeout: 10 * time.Second,
		IdleTimeout:  60 * time.Second,
	}

	// Start the server in a goroutine so we can handle shutdown signals.
	go func() {
		logger.Info("server starting", "port", cfg.Port)
		if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			logger.Error("server error", "err", err)
			os.Exit(1)
		}
	}()

	quit := make(chan os.Signal, 1)
	signal.Notify(quit, syscall.SIGINT, syscall.SIGTERM)
	<-quit

	logger.Info("shutting down gracefully")
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	if err := srv.Shutdown(shutdownCtx); err != nil {
		logger.Error("shutdown error", "err", err)
	}
}
