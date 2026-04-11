#!/usr/bin/env bash
# deploy.sh — rebuild images, restart containers cleanly, run migrations and seeds.
#
# Usage (from infra/ on the server):
#   ./deploy.sh
#
# Options:
#   --no-web     skip rebuilding nimbus-web (faster when only backend changed)
#   --no-build   skip docker build (just restart + seed — useful after config-only changes)

set -euo pipefail

COMPOSE="docker compose -f docker-compose.yml"
SKIP_WEB=false
SKIP_BUILD=false

for arg in "$@"; do
  case "$arg" in
    --no-web)   SKIP_WEB=true ;;
    --no-build) SKIP_BUILD=true ;;
    *) echo "Unknown option: $arg" >&2; exit 1 ;;
  esac
done

# ── 1. Build images ──────────────────────────────────────────────────────────
if [[ "$SKIP_BUILD" == false ]]; then
  echo "==> Building nimbus-api..."
  $COMPOSE build nimbus-api

  if [[ "$SKIP_WEB" == false ]]; then
    echo "==> Building nimbus-web..."
    $COMPOSE build nimbus-web
  fi
fi

# ── 2. Start stateful services first (db + redis) ────────────────────────────
echo "==> Starting nimbus-db and nimbus-redis..."
$COMPOSE up -d --no-deps nimbus-db nimbus-redis

# Wait for Postgres to be ready (healthcheck drives this)
echo "==> Waiting for nimbus-db to be healthy..."
until $COMPOSE exec -T nimbus-db pg_isready -U nimbus -d nimbus_backend -q; do
  sleep 2
done

# ── 3. Run schema migrations ─────────────────────────────────────────────────
echo "==> Running migrations..."
$COMPOSE run --rm --no-deps \
  -e DATABASE_URL="postgres://nimbus:${DB_PASSWORD:-$(grep DB_PASSWORD .env | cut -d= -f2)}@nimbus-db:5432/nimbus_backend" \
  nimbus-api /migrate up

# ── 4. Seed required data ─────────────────────────────────────────────────────
echo "==> Seeding products table..."
$COMPOSE exec -T nimbus-db psql -U nimbus -d nimbus_backend <<'SQL'
INSERT INTO products (slug, name)
VALUES ('nimbus', 'Nimbus')
ON CONFLICT (slug) DO NOTHING;
SQL

echo "==> Seed complete."

# ── 5. Restart application containers ────────────────────────────────────────
echo "==> Restarting nimbus-api..."
$COMPOSE up -d --no-deps --force-recreate nimbus-api

if [[ "$SKIP_WEB" == false ]]; then
  echo "==> Restarting nimbus-web..."
  $COMPOSE up -d --no-deps --force-recreate nimbus-web
fi

# ── 6. Quick health check ────────────────────────────────────────────────────
echo "==> Waiting for nimbus-api to be healthy..."
for i in $(seq 1 30); do
  if $COMPOSE exec -T nimbus-api wget -qO- http://localhost:8090/health &>/dev/null; then
    echo "==> nimbus-api is up."
    break
  fi
  if [[ "$i" == 30 ]]; then
    echo "ERROR: nimbus-api did not become healthy in time." >&2
    $COMPOSE logs --tail=50 nimbus-api
    exit 1
  fi
  sleep 2
done

echo ""
echo "✓ Deploy complete."
