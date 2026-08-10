#!/usr/bin/env bash
#
# Builds an installable plugin zip at build/autokeywordsai.zip.
#
# WordPress expects the zip to contain a single top-level directory matching the plugin
# slug, so the files are staged under build/autokeywordsai/ before zipping.

set -euo pipefail

SLUG="autokeywordsai"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD="$ROOT/build"
STAGE="$BUILD/$SLUG"

rm -rf "$BUILD"
mkdir -p "$STAGE"

cp "$ROOT/$SLUG.php" "$STAGE/"
cp "$ROOT/README.md" "$STAGE/"
cp -R "$ROOT/includes" "$STAGE/"

cd "$BUILD"
zip -qr "$SLUG.zip" "$SLUG"

echo "built $BUILD/$SLUG.zip"
