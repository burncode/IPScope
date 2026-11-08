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
php tests/docker-ip-hook.php
php tests/docker-ip-assistant-ui.php
php tests/snapshot-store.php
php tests/snapshot-store-concurrency.php
php tests/snapshot-store-failures.php
php tests/snapshot-store-recovery.php
php tests/scan-cli-dependencies.php
php tests/docker-client.php
php tests/process-runner.php
php tests/i18n-engine.php

if command -v node >/dev/null 2>&1; then
  node --check src/www/assets/ipscope.js
  node --check src/www/assets/docker-ip-assistant.js
  node --check src/www/assets/i18n.js
fi

php -l packaging/include/docker-hook.php

echo "All local IPScope checks passed."
