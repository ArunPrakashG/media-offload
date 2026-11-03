#!/usr/bin/env bash
# Package apps/plugin/nimbus into dist/nimbus-<version>.zip plus a .sha256 file.
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
mkdir -p "$dist" "$build/nimbus"

(cd "$plugin" && git ls-files -z . | xargs -0 -I{} cp --parents {} "$build/nimbus/")

docker run --rm --user "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
  -v "$build/nimbus:/app" -w /app composer:2 \
  install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet

(
  cd "$build/nimbus"
  grep -v '^\s*#' .distignore | grep -v '^\s*$' | while read -r path; do rm -rf -- "./$path"; done
  rm -f .distignore
)

zip_path="$dist/nimbus-$version.zip"
rm -f "$zip_path"
(cd "$build" && zip -qr -X "$zip_path" nimbus)
(cd "$dist" && sha256sum "nimbus-$version.zip" > "nimbus-$version.zip.sha256")

echo "$zip_path ($(du -h "$zip_path" | cut -f1))"
cat "$dist/nimbus-$version.zip.sha256"
