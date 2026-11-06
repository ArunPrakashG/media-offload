#!/usr/bin/env bash
# Package apps/plugin/nimbus into dist/usenimbus-media-offload-<version>.zip plus a
# .sha256 file. The zip holds one usenimbus-media-offload/ folder, the WordPress.org slug.
#
# Copies the tracked plugin files, runs `composer install --no-dev` in Docker
# (the AWS SDK is trimmed to the S3 client by composer.json), drops everything
# listed in .distignore and zips the result. Needs git, docker, zip and
# sha256sum. Local only: it does not tag, push or publish anything.
set -euo pipefail

root="$(git rev-parse --show-toplevel)"
plugin="$root/apps/plugin/nimbus"
version="$(sed -n "s/^define('NIMBUS_VERSION', '\([^']*\)');/\1/p" "$plugin/nimbus.php")"
[ -n "$version" ] || { echo "Could not read NIMBUS_VERSION from nimbus.php" >&2; exit 1; }

dist="$root/dist"
build="$(mktemp -d)"
trap 'rm -rf "$build"' EXIT
slug="usenimbus-media-offload"
mkdir -p "$dist" "$build/$slug"

(cd "$plugin" && git ls-files -z . | xargs -0 -I{} cp --parents {} "$build/$slug/")

docker run --rm --user "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
  -v "$build/$slug:/app" -w /app composer:2 \
  install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet

(
  cd "$build/$slug"
  grep -v '^\s*#' .distignore | grep -v '^\s*$' | while read -r path; do rm -rf -- "./$path"; done
  rm -f .distignore
)

zip_path="$dist/$slug-$version.zip"
rm -f "$zip_path"
(cd "$build" && zip -qr -X "$zip_path" "$slug")
(cd "$dist" && sha256sum "$slug-$version.zip" > "$slug-$version.zip.sha256")

echo "$zip_path ($(du -h "$zip_path" | cut -f1))"
cat "$dist/$slug-$version.zip.sha256"
