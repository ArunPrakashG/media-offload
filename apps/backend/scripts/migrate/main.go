// migrate applies or rolls back SQL migrations against the DATABASE_URL.
//
// Usage:
//
//	go run ./scripts/migrate up    # apply all pending migrations
//	go run ./scripts/migrate down  # roll back the last migration
package main

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"

	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/joho/godotenv"
)

const migrationsDir = "migrations"

func main() {
	_ = godotenv.Load()

	direction := "up"
	if len(os.Args) > 1 {
		direction = os.Args[1]
	}
	if direction != "up" && direction != "down" {
		fmt.Fprintln(os.Stderr, "usage: migrate [up|down]")
		os.Exit(1)
	}

	dbURL := os.Getenv("DATABASE_URL")
	if dbURL == "" {
		fmt.Fprintln(os.Stderr, "DATABASE_URL is not set")
		os.Exit(1)
	}

	ctx := context.Background()
	pool, err := pgxpool.New(ctx, dbURL)
	if err != nil {
		fatalf("connect: %v", err)
	}
	defer pool.Close()

	if err := ensureMigrationsTable(ctx, pool); err != nil {
		fatalf("ensure schema_migrations: %v", err)
	}

	applied, err := appliedVersions(ctx, pool)
	if err != nil {
		fatalf("list applied: %v", err)
	}

	files, err := migrationFiles(direction)
	if err != nil {
		fatalf("list files: %v", err)
	}

	if direction == "up" {
		for _, f := range files {
			ver := fileVersion(f)
			if applied[ver] {
				continue
			}
			if err := runFile(ctx, pool, f); err != nil {
				fatalf("apply %s: %v", f, err)
			}
			if _, err := pool.Exec(ctx,
				`INSERT INTO schema_migrations (version) VALUES ($1) ON CONFLICT DO NOTHING`, ver,
			); err != nil {
				fatalf("record migration %d: %v", ver, err)
			}
			fmt.Printf("Applied: %s\n", filepath.Base(f))
		}
	} else {
		// Roll back: run the highest applied version's .down.sql
		if len(applied) == 0 {
			fmt.Println("Nothing to roll back.")
			return
		}
		maxVer := 0
		for v := range applied {
			if v > maxVer {
				maxVer = v
			}
		}
		downFiles, _ := filepath.Glob(fmt.Sprintf("%s/%03d_*.down.sql", migrationsDir, maxVer))
		if len(downFiles) == 0 {
			fatalf("no down migration found for version %d", maxVer)
		}
		downFile := downFiles[0]
		if err := runFile(ctx, pool, downFile); err != nil {
			fatalf("rollback %s: %v", downFile, err)
		}
		if _, err := pool.Exec(ctx,
			`DELETE FROM schema_migrations WHERE version = $1`, maxVer,
		); err != nil {
			fatalf("remove migration record: %v", err)
		}
		fmt.Printf("Rolled back: %s\n", filepath.Base(downFile))
	}
}

func ensureMigrationsTable(ctx context.Context, pool *pgxpool.Pool) error {
	_, err := pool.Exec(ctx, `
		CREATE TABLE IF NOT EXISTS schema_migrations (
			version    INT         PRIMARY KEY,
			applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
		)`)
	return err
}

func appliedVersions(ctx context.Context, pool *pgxpool.Pool) (map[int]bool, error) {
	rows, err := pool.Query(ctx, `SELECT version FROM schema_migrations`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	m := map[int]bool{}
	for rows.Next() {
		var v int
		if err := rows.Scan(&v); err != nil {
			return nil, err
		}
		m[v] = true
	}
	return m, rows.Err()
}

func migrationFiles(direction string) ([]string, error) {
	pattern := filepath.Join(migrationsDir, "*."+direction+".sql")
	files, err := filepath.Glob(pattern)
	if err != nil {
		return nil, err
	}
	sort.Strings(files)
	return files, nil
}

func fileVersion(path string) int {
	base := filepath.Base(path)
	parts := strings.SplitN(base, "_", 2)
	v, _ := strconv.Atoi(parts[0])
	return v
}

func runFile(ctx context.Context, pool *pgxpool.Pool, path string) error {
	data, err := os.ReadFile(path)
	if err != nil {
		return err
	}
	_, err = pool.Exec(ctx, string(data))
	return err
}

func fatalf(format string, args ...any) {
	fmt.Fprintf(os.Stderr, "migrate: "+format+"\n", args...)
	os.Exit(1)
}
