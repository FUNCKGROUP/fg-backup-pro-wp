#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="$(basename "$ROOT")"
BUILD_DIR="${ROOT}/build"
STAGE_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"
ZIP_FILE="${BUILD_DIR}/${PLUGIN_SLUG}.zip"

# Core aktualisieren, wenn eine Entwicklungsquelle vorhanden ist. Ansonsten
# den bereits eingebetteten und versionierten Core verwenden.
if [[ -n "${FG_CORE_SOURCE:-}" ]] \
  || [[ -f "${ROOT}/vendor/funckgroup/fg-core/includes/fg-core/bootstrap.php" ]] \
  || [[ -f "${ROOT}/vendor/funckgroup/fg-core/package/bootstrap.php" ]] \
  || [[ -f "$(dirname "$ROOT")/fg-core/includes/fg-core/bootstrap.php" ]] \
  || [[ -f "$(dirname "$ROOT")/fg-core/package/bootstrap.php" ]]; then
  php "${ROOT}/tools/sync-fg-core.php"
elif [[ ! -f "${ROOT}/includes/fg-core/bootstrap.php" ]]; then
  echo "FG Core fehlt unter includes/fg-core und es wurde keine Sync-Quelle gefunden." >&2
  exit 1
else
  echo "Verwende den bereits eingebetteten FG Core."
fi

rm -rf "$BUILD_DIR"
mkdir -p "$STAGE_DIR"

rsync -a \
  --exclude='.git' \
  --exclude='.gitignore' \
  --exclude='build' \
  --exclude='vendor' \
  --exclude='composer.json' \
  --exclude='composer.lock' \
  --exclude='composer.private-example.json' \
  --exclude='CLAUDE.md' \
  --exclude='docs' \
  --exclude='tools' \
  "$ROOT/" "$STAGE_DIR/"

(
  cd "$BUILD_DIR"
  zip -qr "$(basename "$ZIP_FILE")" "$PLUGIN_SLUG"
)

echo "Plugin-ZIP erstellt: $ZIP_FILE"
