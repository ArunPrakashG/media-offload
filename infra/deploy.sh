#!/usr/bin/env bash
# deploy.sh — rebuild the web frontend image and restart it cleanly.
#
# Usage (from infra/ on the server):
#   ./deploy.sh
#
# Options:
#   --no-build   skip docker build (just restart — useful after config-only changes)

set -euo pipefail

COMPOSE="docker compose -f docker-compose.yml"
SKIP_BUILD=false

for arg in "$@"; do
  case "$arg" in
    --no-build) SKIP_BUILD=true ;;
    *) echo "Unknown option: $arg" >&2; exit 1 ;;
  esac
done

# ── 1. Build image ───────────────────────────────────────────────────────────
if [[ "$SKIP_BUILD" == false ]]; then
  echo "==> Building nimbus-web..."
  $COMPOSE build nimbus-web
fi

# ── 2. Restart application container ─────────────────────────────────────────
echo "==> Restarting nimbus-web..."
$COMPOSE up -d --no-deps --force-recreate nimbus-web

echo ""
echo "✓ Deploy complete."
