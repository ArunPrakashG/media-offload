# Nimbus — Backend

Generic licensing API for Nimbus products. One backend serves all plugins — not tied to any single plugin's logic.

> **Payments:** Handled manually for now (email + UPI). The backend issues and manages license keys; payment collection is out of scope until a payment provider is integrated.

## How purchasing works (current flow)

1. Customer contacts via the website's **Contact / Purchase** page.
2. You confirm payment over email and receive UPI transfer.
3. You use the **admin CLI** (`nimbus-admin key create`) to generate a license key for the customer's email, product, and tier.
4. You email the key to the customer.
5. Customer pastes it into the plugin — the plugin calls `/v1/licenses/activate` and gets back a signed token.

No payment webhook, no subscription automation. When you are ready to plug in a payment provider (Cashfree, Razorpay, or anything else), you add a webhook handler that calls the same `key create` logic automatically.

## Design

License validation uses an **offline-first, signed-token model**:

1. A WordPress plugin calls `POST /v1/licenses/activate` once per domain. The backend validates the key, checks activation slot availability, and returns an **Ed25519-signed JWT** (30-day TTL) containing `product_id`, `tier`, `domain`, `features`, and `exp`.
2. The plugin stores the JWT in `wp_options` and validates it **locally on every boot** using the embedded Ed25519 public key — no HTTP call needed for normal operation.
3. When the JWT expires, the plugin silently re-activates. If the key is revoked, the backend refuses re-activation and the plugin disables itself after the TTL window.

This means:

- Your server going down does not break legitimate users (30-day local grace)
- Tokens cannot be forged without the private key (Ed25519)
- Domain is embedded in the token — a token for `site-a.com` is invalid on `site-b.com`
- Product ID is embedded — a Nimbus token cannot unlock a different plugin

## Stack

| Layer                 | Choice                                                             |
| --------------------- | ------------------------------------------------------------------ |
| Language              | Go 1.22+                                                           |
| Router                | [chi](https://github.com/go-chi/chi)                               |
| Database              | PostgreSQL 16                                                      |
| Cache / rate-limiting | Redis                                                              |
| License signing       | Ed25519 (`crypto/ed25519` stdlib)                                  |
| DB queries            | [sqlc](https://sqlc.dev/) + [pgx/v5](https://github.com/jackc/pgx) |
| Config                | Environment variables (`godotenv` in dev)                          |
| Payments              | **Not integrated yet** — manual via email + UPI                    |

## Project layout

```
apps/backend/
├── cmd/
│   ├── server/         main.go — HTTP API server
│   └── admin/          main.go — CLI for key management (nimbus-admin)
├── internal/
│   ├── api/            HTTP handlers (chi router)
│   │   ├── licenses/   activate, deactivate, validate
│   │   └── middleware/ rate-limit, logging, recovery
│   ├── license/        Core domain logic (activation, signing, revocation)
│   ├── db/             sqlc-generated queries + migrations
│   └── config/         Env-var config struct
├── migrations/         SQL migration files (*.up.sql / *.down.sql)
├── scripts/            Key generation, local seed data
├── .env.example
├── Dockerfile
└── README.md
```

## REST API

All endpoints are versioned under `/v1/`.

| Method | Path                      | Description                                    |
| ------ | ------------------------- | ---------------------------------------------- |
| `POST` | `/v1/licenses/activate`   | Activate a key on a domain; returns signed JWT |
| `POST` | `/v1/licenses/deactivate` | Remove a domain activation                     |
| `GET`  | `/v1/licenses/status`     | Check key status without issuing a new token   |

### Activate request / response

```jsonc
// POST /v1/licenses/activate
{
  "key": "nmb_live_xxxxxxxxxxxxxxxx",
  "domain": "https://example.com",
  "product": "nimbus",
  "plugin_version": "1.0.0"
}

// 200 OK
{
  "token": "<ed25519-signed-jwt>",   // store in wp_options; validate locally
  "expires_at": "2026-05-04T00:00:00Z",
  "tier": "pro",
  "features": ["offload", "cdn", "multisite"]
}
```

## Admin CLI (`nimbus-admin`)

Keys are managed through a CLI tool that connects directly to the database. No admin web UI needed yet.

```bash
# Issue a new license key
nimbus-admin key create \
  --email customer@example.com \
  --product nimbus \
  --tier pro \
  --activations 3 \
  --expires-in 1y

# List all keys for a customer
nimbus-admin key list --email customer@example.com

# Revoke a key immediately
nimbus-admin key revoke nmb_live_xxxxxxxxxxxxxxxx

# Extend expiry
nimbus-admin key extend nmb_live_xxxxxxxxxxxxxxxx --by 1y

# List active activations for a key
nimbus-admin key activations nmb_live_xxxxxxxxxxxxxxxx
```

## Database schema (core tables)

```sql
-- One row per product (nimbus, future-plugin, …)
products        (id, slug, name, created_at)

-- One row per customer
customers       (id, email, notes, created_at)

-- One row per issued license
licenses        (id, product_id, customer_id, key, tier,
                 status, max_activations, expires_at, created_at)

-- One row per active domain
activations     (id, license_id, domain, activated_at,
                 last_seen_at, token_issued_at)

-- Immutable audit log
license_events  (id, license_id, event_type, metadata jsonb, created_at)
```

## Security

- **Ed25519 signing** — private key never leaves the server; public key is embedded in each plugin
- **Domain binding** — domain is normalized (strips `www.`, scheme, trailing slash) and embedded in token
- **Activation slot locking** — row-level DB lock during activation; concurrent requests cannot exceed `max_activations`
- **Rate limiting** — Redis sliding window: 10 attempts / (IP + key) / minute on the activate endpoint
- **Idempotency** — re-activating the same key+domain refreshes the token, never burns a new slot

## Development

```bash
# Prerequisites: Go 1.22+, Docker (for Postgres + Redis)

# Start dependencies
docker compose -f docker-compose.dev.yml up -d

# Copy env
cp .env.example .env

# Generate Ed25519 keypair (first time only)
go run ./scripts/gen-keys

# Run migrations
go run ./scripts/migrate up

# Start API server
go run ./cmd/server

# Use the admin CLI
go run ./cmd/admin key list
```

## Environment variables

| Variable              | Description                        |
| --------------------- | ---------------------------------- |
| `DATABASE_URL`        | `postgres://user:pass@host/dbname` |
| `REDIS_URL`           | `redis://localhost:6379`           |
| `LICENSE_PRIVATE_KEY` | Base64-encoded Ed25519 private key |
| `PORT`                | HTTP listen port (default `8090`)  |

## Adding a payment provider later

When you are ready to automate key issuance (Razorpay, Cashfree, etc.):

1. Add a `POST /v1/webhooks/<provider>` handler that verifies the HMAC signature.
2. On a successful payment event, call the same `license.Create()` function the CLI uses.
3. Email the key to the customer (send via any transactional email provider).

No core licensing logic changes needed.

License validation uses an **offline-first, signed-token model**:

1. A WordPress plugin calls `POST /v1/licenses/activate` once per domain. The backend validates the key, checks activation slot availability, and returns an **Ed25519-signed JWT** (30-day TTL) containing `product_id`, `tier`, `domain`, `features`, and `exp`.
2. The plugin stores the JWT in `wp_options` and validates it **locally on every boot** using the embedded Ed25519 public key — no HTTP call needed for normal operation.
3. When the JWT expires the plugin silently re-activates. If the key is revoked, the backend refuses re-activation and the plugin disables itself after the TTL window.

This means:

- Your server going down does not break legitimate users (30-day local grace)
- Tokens cannot be forged without the private key (Ed25519)
- Domain is embedded in the token — a token for `site-a.com` is invalid on `site-b.com`
- Product ID is embedded — a Nimbus token cannot unlock a different plugin

## Stack

| Layer                 | Choice                                                             |
| --------------------- | ------------------------------------------------------------------ |
| Language              | Go 1.22+                                                           |
| Router                | [chi](https://github.com/go-chi/chi)                               |
| Database              | PostgreSQL 16                                                      |
| Cache / rate-limiting | Redis                                                              |
| License signing       | Ed25519 (`crypto/ed25519` stdlib)                                  |
| Payments              | Stripe                                                             |
| DB queries            | [sqlc](https://sqlc.dev/) + [pgx/v5](https://github.com/jackc/pgx) |
| Config                | Environment variables (`godotenv` in dev)                          |

## Project layout

```
apps/backend/
├── cmd/
│   └── server/         main.go — entry point
├── internal/
│   ├── api/            HTTP handlers (chi router)
│   │   ├── licenses/   activate, deactivate, validate
│   │   ├── webhooks/   Stripe webhook handler
│   │   └── middleware/ rate-limit, auth, logging
│   ├── license/        Core domain logic (activation, signing, revocation)
│   ├── db/             sqlc-generated queries + migrations
│   ├── stripe/         Stripe client wrapper
│   └── config/         Env-var config struct
├── migrations/         SQL migration files (*.up.sql / *.down.sql)
├── scripts/            Key generation, local seed data
├── .env.example
├── Dockerfile
└── README.md
```

## REST API

All endpoints are versioned under `/v1/`.

| Method | Path                      | Auth            | Description                                      |
| ------ | ------------------------- | --------------- | ------------------------------------------------ |
| `POST` | `/v1/licenses/activate`   | Key in body     | Activate a key on a domain; returns signed JWT   |
| `POST` | `/v1/licenses/deactivate` | Key in body     | Remove a domain activation                       |
| `GET`  | `/v1/licenses/validate`   | Key in body     | Validate key status (does not issue a new token) |
| `POST` | `/v1/webhooks/stripe`     | HMAC sig header | Stripe event handler                             |

### Activate request / response

```jsonc
// POST /v1/licenses/activate
{
  "key": "nmb_live_xxxxxxxxxxxxxxxx",
  "domain": "https://example.com",
  "product": "nimbus",
  "plugin_version": "1.0.0"
}

// 200 OK
{
  "token": "<ed25519-signed-jwt>",   // store in wp_options; validate locally
  "expires_at": "2026-05-04T00:00:00Z",
  "tier": "pro",
  "features": ["offload", "cdn", "multisite"]
}
```

## Security

- **Ed25519 signing** — private key never leaves the server; public key is embedded in each plugin
- **Domain binding** — domain is normalized (strips `www.`, scheme, trailing slash) and embedded in token
- **Activation slot locking** — row-level DB lock during activation; concurrent requests cannot exceed `max_activations`
- **Rate limiting** — Redis sliding window: 10 attempts / (IP + key) / minute on activate endpoint
- **Idempotency** — re-activating the same key+domain refreshes the token, never burns a new slot
- **Stripe webhook HMAC** — signature verified before any DB write

## Development

```bash
# Prerequisites: Go 1.22+, Docker (for Postgres + Redis)

# Start dependencies
docker compose up -d postgres redis

# Copy env
cp .env.example .env

# Generate Ed25519 keypair (first time)
go run ./scripts/gen-keys

# Run migrations
go run ./scripts/migrate up

# Start server
go run ./cmd/server
```

## Environment variables

| Variable                | Description                        |
| ----------------------- | ---------------------------------- |
| `DATABASE_URL`          | `postgres://user:pass@host/dbname` |
| `REDIS_URL`             | `redis://localhost:6379`           |
| `LICENSE_PRIVATE_KEY`   | Base64-encoded Ed25519 private key |
| `STRIPE_SECRET_KEY`     | Stripe secret API key              |
| `STRIPE_WEBHOOK_SECRET` | Stripe webhook signing secret      |
| `PORT`                  | HTTP listen port (default `8090`)  |
