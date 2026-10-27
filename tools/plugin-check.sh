#!/usr/bin/env bash
set -euo pipefail

# Runs the official Plugin Check static scan against the exact ZIP intended for
# submission. Set WP_PATH to an isolated local WordPress installation.
if [[ -z "${WP_PATH:-}" ]]; then
  echo "Set WP_PATH to a local WordPress installation, for example:" >&2
  echo "  WP_PATH=/path/to/wordpress ./tools/plugin-check.sh" >&2
  exit 2
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_MAIN="${ROOT}/digitalownership-publication-verification.php"
VERSION="$(sed -n "s/define('DOVP_VERSION', '\([^']*\)');/\1/p" "$PLUGIN_MAIN")"
PACKAGE="${ROOT}/dist/digitalownership-publication-verification-${VERSION}.zip"

if [[ ! -d "${WP_PATH}" || ! -f "${WP_PATH}/wp-load.php" ]]; then
  echo "WP_PATH is not a WordPress installation: ${WP_PATH}" >&2
  exit 2
fi

node "${ROOT}/tools/build-release.mjs"
wp --path="${WP_PATH}" plugin install plugin-check --activate
wp --path="${WP_PATH}" plugin check "${PACKAGE}"

echo "Plugin Check static scan completed for ${PACKAGE}."
echo "For runtime checks, run this manually after reviewing the static output:"
echo "wp --path=\"${WP_PATH}\" plugin check \"${PACKAGE}\" --require=\"${WP_PATH}/wp-content/plugins/plugin-check/cli.php\""
