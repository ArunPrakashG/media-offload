package db

import (
	"context"
	"fmt"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
	"github.com/jackc/pgx/v5/pgxpool"
)

// DBTX is the common interface satisfied by both *pgxpool.Pool and pgx.Tx.
// All query functions accept DBTX so they work inside or outside transactions.
type DBTX interface {
	Exec(context.Context, string, ...any) (pgconn.CommandTag, error)
	Query(context.Context, string, ...any) (pgx.Rows, error)
	QueryRow(context.Context, string, ...any) pgx.Row
}

// DB wraps a pgxpool.Pool and exposes helpers for transactions.
type DB struct {
	pool *pgxpool.Pool
}

// New creates a new DB, verifying connectivity with a ping.
func New(ctx context.Context, url string) (*DB, error) {
	pool, err := pgxpool.New(ctx, url)
	if err != nil {
		return nil, fmt.Errorf("create pool: %w", err)
	}
	if err := pool.Ping(ctx); err != nil {
		return nil, fmt.Errorf("ping database: %w", err)
	}
	return &DB{pool: pool}, nil
}

// Close shuts down the connection pool.
func (d *DB) Close() {
	d.pool.Close()
}

// Pool returns the underlying connection pool for non-transactional queries.
func (d *DB) Pool() DBTX {
	return d.pool
}

// WithTx runs fn inside a serializable transaction.
// The transaction is automatically rolled back if fn returns an error.
func (d *DB) WithTx(ctx context.Context, fn func(context.Context, DBTX) error) error {
	tx, err := d.pool.Begin(ctx)
	if err != nil {
		return fmt.Errorf("begin tx: %w", err)
	}
	defer tx.Rollback(ctx) //nolint:errcheck

	if err := fn(ctx, tx); err != nil {
		return err
	}
	return tx.Commit(ctx)
}
