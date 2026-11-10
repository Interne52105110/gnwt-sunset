#!/usr/bin/env bash
# Builds build/upload/sunset for a first installation on shared hosting
# without a shell: the deployable tree, install-time settings, the data
# package, and a one-time extractor whose file name carries a random key.
# Later releases go through the pipeline (deploy/push.php), not this script.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD="$ROOT/build"
RELEASE="$BUILD/sunset"

rm -rf "$BUILD/sunset" "$BUILD/upload"
bash "$ROOT/deploy/build-dist.sh" "$RELEASE"
mkdir -p "$RELEASE/config/sync" "$RELEASE/private" "$RELEASE/data"

cp "$ROOT/deploy/settings.php" "$RELEASE/web/sites/default/settings.php"
cp "$ROOT/drupal/data/sunset-package.jsonl.gz" "$RELEASE/data/"

python -I "$ROOT/deploy/zip_release.py" "$RELEASE" "$BUILD/sunset-release.zip"

TOKEN="$(python -I -c 'import secrets; print(secrets.token_hex(16))')"
mkdir -p "$BUILD/upload/sunset/web"
mv "$BUILD/sunset-release.zip" "$BUILD/upload/sunset/"
sed "s/__TOKEN__/$TOKEN/" "$ROOT/deploy/extract.php" > "$BUILD/upload/sunset/web/extract-$TOKEN.php"
echo "Upload build/upload/sunset, then open /extract-$TOKEN.php?k=$TOKEN"
