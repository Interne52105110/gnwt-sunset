#!/usr/bin/env bash
# Assembles the tree that lives in the hosting folder: production
# dependencies, custom code, shared-hosting patches and the deployment
# receiver. Server-owned files (web/sites: settings.php, uploads) are built
# but never sent by deploy/push.php.
#
# Usage: deploy/build-dist.sh [target-folder]   (default: build/dist)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="${1:-$ROOT/build/dist}"

rm -rf "$DIST"
mkdir -p "$DIST/web/modules" "$DIST/web/profiles" "$DIST/web/themes"

cp "$ROOT/drupal/composer.json" "$ROOT/drupal/composer.lock" "$DIST/"
cp -r "$ROOT/drupal/web/modules/custom" "$DIST/web/modules/"
cp -r "$ROOT/drupal/web/profiles/custom" "$DIST/web/profiles/"
cp -r "$ROOT/drupal/web/themes/custom" "$DIST/web/themes/"

(cd "$DIST" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress)

# Shared-hosting fixes to Drupal core, documented in each patch file.
for patch_file in "$ROOT"/deploy/patches/*.patch; do
  patch -p1 -d "$DIST/web" < "$patch_file"
done

cp "$ROOT/deploy/.ovhconfig" "$DIST/web/.ovhconfig"

# The receiver carries the public half of the signing key.
if [ -s "$ROOT/deploy/deploy-key.pub" ]; then
  sed "s|__PUBLIC_KEY__|$(tr -d '\r\n' < "$ROOT/deploy/deploy-key.pub")|" "$ROOT/deploy/receiver.php" > "$DIST/web/_deploy.php"
else
  echo "deploy/deploy-key.pub is missing: run deploy/keygen.php first." >&2
  exit 1
fi

echo "Built $DIST"
