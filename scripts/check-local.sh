#!/usr/bin/env sh
set -eu

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
cd "$ROOT"

if ! command -v php >/dev/null 2>&1; then
  echo "PHP CLI is required for local checks." >&2
  exit 1
fi

find src tests -type f -name '*.php' -print | while IFS= read -r file; do
  php -l "$file"
done

php tests/smoke.php
php tests/template-parser.php
php tests/address-inventory.php
php tests/address-exclusions.php
php tests/address-recommendations.php
php tests/snapshot-store.php

if command -v node >/dev/null 2>&1; then
  node --check src/www/assets/ipscope.js
fi

echo "All local IPScope checks passed."
