#!/usr/bin/env bash
# Publish dist/nimbus-<version>.zip to the WordPress.org plugin SVN repository.
#
# Run `bash scripts/release-plugin.sh` first. This checks out the plugin's SVN
# repo, replaces trunk with the zip contents, copies trunk to tags/<version>
# and syncs assets/ from apps/plugin/nimbus/.wordpress-org. It shows the
# changes and asks before committing; svn prompts for your WordPress.org
# username and SVN password. Needs svn and unzip.
#
# Env: WPORG_SVN_URL overrides the repo URL (default: the nimbus plugin).
set -euo pipefail

root="$(git rev-parse --show-toplevel)"
plugin="$root/apps/plugin/nimbus"
version="$(sed -n "s/^define('NIMBUS_VERSION', '\([^']*\)');/\1/p" "$plugin/nimbus.php")"
zip_path="$root/dist/nimbus-$version.zip"
svn_url="${WPORG_SVN_URL:-https://plugins.svn.wordpress.org/nimbus}"

[ -f "$zip_path" ] || { echo "Missing $zip_path. Run scripts/release-plugin.sh first." >&2; exit 1; }
stable="$(sed -n 's/^Stable tag: *//p' "$plugin/readme.txt" | tr -d '\r')"
[ "$stable" = "$version" ] || { echo "readme.txt Stable tag ($stable) does not match $version." >&2; exit 1; }

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

svn checkout -q --depth immediates "$svn_url" "$work/svn"
svn update -q --set-depth infinity "$work/svn/trunk" "$work/svn/assets"
if [ -e "$work/svn/tags/$version" ] || svn ls "$svn_url/tags/$version" >/dev/null 2>&1; then
  echo "tags/$version already exists in SVN. Bump the version first." >&2
  exit 1
fi

unzip -q "$zip_path" -d "$work/zip"
rsync -a --delete --exclude .svn "$work/zip/nimbus/" "$work/svn/trunk/"
rsync -a --delete --exclude .svn "$plugin/.wordpress-org/" "$work/svn/assets/"

cd "$work/svn"
svn status trunk assets | awk '/^\?/ {print $2}' | xargs -r svn add -q --parents
svn status trunk assets | awk '/^!/ {print $2}' | xargs -r svn delete -q
for png in assets/*.png; do
  svn propset -q svn:mime-type image/png "$png"
done
[ ! -f assets/icon.svg ] || svn propset -q --force svn:mime-type image/svg+xml assets/icon.svg
svn copy -q trunk "tags/$version"

svn status | sed -n '1,40p'
echo "$(svn status | wc -l) changes for Nimbus $version."
read -r -p "Commit to $svn_url? [y/N] " answer
[ "$answer" = "y" ] || { echo "Nothing committed."; exit 0; }

svn commit -m "Release $version"
