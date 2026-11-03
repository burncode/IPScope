#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-}"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this script directly in Unraid SSH as root." >&2
  echo "Example: bash scripts/install-local.sh" >&2
  exit 1
fi

if [[ -z "$VERSION" ]]; then
  VERSION="0.1.1-dev.$(date +%Y%m%d%H%M%S)"
fi
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][A-Za-z0-9.-]+)?$ ]]; then
  echo "Invalid version: $VERSION" >&2
  echo "Usage: $0 [version]" >&2
  exit 2
fi

for cmd in php tar xz md5sum sha256sum upgradepkg; do
  command -v "$cmd" >/dev/null 2>&1 || {
    echo "Missing required command: $cmd" >&2
    exit 1
  }
done

echo "==> Building IPScope $VERSION"
bash "$ROOT/scripts/build-package.sh" "$VERSION"

PACKAGE="$ROOT/dist/ipscope-$VERSION-noarch-1.txz"
PLG="$ROOT/dist/ipscope.plg"
if [[ ! -s "$PACKAGE" || ! -s "$PLG" ]]; then
  echo "Package or PLG was not created or is empty." >&2
  exit 1
fi

echo "==> Validating PLG XML and launch metadata"
php -r '
$xml = simplexml_load_file($argv[1]);
if ($xml === false) {
    fwrite(STDERR, "Invalid PLG XML.\n");
    exit(1);
}
if ((string)$xml["launch"] !== "Settings/IPScope") {
    fwrite(STDERR, "Unexpected or missing PLG launch attribute.\n");
    exit(1);
}
if ((string)$xml["icon"] !== "ipscope.png") {
    fwrite(STDERR, "Unexpected or missing PLG icon attribute.\n");
    exit(1);
}
' "$PLG"

PACKAGE_CONTENTS="$(tar -tJf "$PACKAGE")"
grep -Fxq './usr/local/emhttp/plugins/ipscope/IPScope.page' <<< "$PACKAGE_CONTENTS"
grep -Fxq './usr/local/emhttp/plugins/ipscope/api.php' <<< "$PACKAGE_CONTENTS"
grep -Fxq './usr/local/emhttp/plugins/ipscope/images/ipscope.png' <<< "$PACKAGE_CONTENTS"

echo "==> Installing/replacing IPScope locally"
echo "Package: $PACKAGE"
upgradepkg --install-new "$PACKAGE"

echo
echo "IPScope $VERSION installation completed."
echo "Open Settings > User Utilities > IPScope."
echo "The installed-plugin entry should also open Settings/IPScope."
echo "If the page is already open, refresh it with Ctrl+F5."
