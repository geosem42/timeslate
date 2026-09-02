#!/usr/bin/env bash
#
# Build the Envato-distributable zip for Timeslate.
#
# What it does:
#   1. Runs `npm run build` to produce compiled block JS + the
#      FullCalendar vendor bundle.
#   2. Regenerates languages/timeslate.pot from the source.
#   3. Copies the plugin directory to a staging area under /tmp.
#   4. Strips everything Envato reviewers reject: dev-only files,
#      block source, node_modules, IDE configs, git metadata, CLAUDE.md.
#   5. Zips the staged copy to ./dist/timeslate-<version>.zip with
#      `timeslate/` as the top-level folder inside.
#
# Run from the plugin root:
#   ./tools/package.sh
#
# Exits non-zero on any build/packaging failure so CI can gate on it.

set -euo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$ROOT"

# Version pulled straight from the plugin header — single source of truth.
VERSION=$( grep -E '^\s*\*\s*Version:' timeslate.php | head -1 | sed -E 's/.*Version:\s*([0-9.]+).*/\1/' )
if [[ -z "$VERSION" ]]; then
  echo "Could not determine plugin version from timeslate.php" >&2
  exit 1
fi

SLUG="timeslate"
DIST_DIR="$ROOT/dist"
STAGE_DIR="$( mktemp -d )/$SLUG"
ZIP_PATH="$DIST_DIR/${SLUG}-${VERSION}.zip"

echo "==> Packaging $SLUG v$VERSION"

# ---- 1. Build compiled assets ---------------------------------------------
echo "==> Running npm run build"
npm run build

# ---- 2. Regenerate .pot ----------------------------------------------------
echo "==> Regenerating .pot"
php tools/make-pot.php

# ---- 3. Stage + strip ------------------------------------------------------
echo "==> Staging at $STAGE_DIR"
mkdir -p "$STAGE_DIR"
# Use rsync with exclude list so we don't accidentally ship anything that
# grows into the tree later — everything is whitelisted by absence, not
# explicit inclusion.
rsync -a \
  --exclude='/.git' \
  --exclude='/.github' \
  --exclude='/.gitignore' \
  --exclude='/.gitattributes' \
  --exclude='/.claude' \
  --exclude='/.vscode' \
  --exclude='/.idea' \
  --exclude='/.DS_Store' \
  --exclude='/node_modules' \
  --exclude='/tools' \
  --exclude='/dist' \
  --exclude='/package.json' \
  --exclude='/package-lock.json' \
  --exclude='/CLAUDE.md' \
  --exclude='/README.md' \
  --exclude='/memory' \
  --exclude='/assets/blocks/src' \
  --exclude='/tests' \
  --exclude='*.log' \
  --exclude='*.swp' \
  --exclude='*.DS_Store' \
  ./ "$STAGE_DIR/"

# Sanity check: fatal rejects if any dev-only artifact sneaks in. Keep
# the pattern list in sync with the rsync excludes above.
SNEAK=(
  ".git" ".github" ".claude" ".vscode" ".idea"
  "node_modules" "package.json" "CLAUDE.md"
  "assets/blocks/src"
)
for item in "${SNEAK[@]}"; do
  if [[ -e "$STAGE_DIR/$item" ]]; then
    echo "LEAK: $item present in staged copy — aborting" >&2
    exit 1
  fi
done

# ---- 4. Zip ----------------------------------------------------------------
mkdir -p "$DIST_DIR"
rm -f "$ZIP_PATH"
( cd "$( dirname "$STAGE_DIR" )" && zip -rq "$ZIP_PATH" "$SLUG" )

BYTES=$( stat -c '%s' "$ZIP_PATH" 2>/dev/null || stat -f '%z' "$ZIP_PATH" )
echo "==> Wrote $ZIP_PATH ($BYTES bytes)"

# ---- 5. Clean stage dir ----------------------------------------------------
rm -rf "$( dirname "$STAGE_DIR" )"

echo "==> Done."
