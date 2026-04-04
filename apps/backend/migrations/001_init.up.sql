-- Enable UUID generation.
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- Products: the purchasable software items.
CREATE TABLE products (
    id         UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    slug       TEXT        UNIQUE NOT NULL,
    name       TEXT        NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Customers: the humans who buy licenses.
CREATE TABLE customers (
    id         UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    email      TEXT        UNIQUE NOT NULL,
    notes      TEXT        NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Licenses: a single purchased key.
--   max_activations = -1  means unlimited.
--   expires_at      = NULL means the key never expires.
--   status values   : active | expired | revoked | suspended
CREATE TABLE licenses (
    id               UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    product_id       UUID        NOT NULL REFERENCES products(id),
    customer_id      UUID        NOT NULL REFERENCES customers(id),
    key              TEXT        UNIQUE NOT NULL,
    tier             TEXT        NOT NULL DEFAULT 'personal',
    status           TEXT        NOT NULL DEFAULT 'active',
    max_activations  INT         NOT NULL DEFAULT 1,
    expires_at       TIMESTAMPTZ,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_licenses_key         ON licenses(key);
CREATE INDEX idx_licenses_customer_id ON licenses(customer_id);
CREATE INDEX idx_licenses_status      ON licenses(status);

-- Activations: maps a license to the domains that have consumed a slot.
CREATE TABLE activations (
    id              UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    license_id      UUID        NOT NULL REFERENCES licenses(id) ON DELETE CASCADE,
    domain          TEXT        NOT NULL,
    activated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_seen_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    token_issued_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(license_id, domain)
);

CREATE INDEX idx_activations_license_id ON activations(license_id);

-- License events: immutable audit log.
CREATE TABLE license_events (
    id          UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    license_id  UUID        NOT NULL REFERENCES licenses(id),
    event_type  TEXT        NOT NULL,
    metadata    JSONB       NOT NULL DEFAULT '{}',
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_license_events_license_id ON license_events(license_id);

-- schema_migrations is created and managed by scripts/migrate.
