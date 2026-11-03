#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOST="${1:-${IPSCOPE_HOST:-}}"
VERSION="${2:-}"

if [[ -z "$HOST" ]]; then
  read -r -p "Unraid SSH target (example: root@192.168.50.21): " HOST
fi
if [[ -z "$HOST" || "$HOST" == -* ]]; then
  echo "Usage: $0 <user@unraid-host> [version]" >&2
  echo "Or set IPSCOPE_HOST. Example: IPSCOPE_HOST=root@192.168.50.21 $0" >&2
  exit 2
fi

if [[ -z "$VERSION" ]]; then
  VERSION="0.1.1-dev.$(date +%Y%m%d%H%M%S)"
fi
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][A-Za-z0-9.-]+)?$ ]]; then
  echo "Invalid version: $VERSION" >&2
  exit 2
fi

for cmd in ssh scp; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "Missing required command: $cmd" >&2; exit 1; }
done

echo "==> Building IPScope $VERSION"
bash "$ROOT/scripts/build-package.sh" "$VERSION"

PACKAGE="$ROOT/dist/ipscope-$VERSION-noarch-1.txz"
PACKAGE_NAME="$(basename "$PACKAGE")"
REMOTE_PACKAGE="/tmp/$PACKAGE_NAME"

if [[ ! -f "$PACKAGE" ]]; then
  echo "Package was not created: $PACKAGE" >&2
  exit 1
fi

# Fail before connecting if the package does not contain the native page and API entry points.
tar -tJf "$PACKAGE" | grep -qx './usr/local/emhttp/plugins/ipscope/IPScope.page'
tar -tJf "$PACKAGE" | grep -qx './usr/local/emhttp/plugins/ipscope/api.php'

echo "==> Checking SSH access to $HOST"
ssh "$HOST" 'test "$(id -u)" -eq 0 && command -v upgradepkg >/dev/null'
echo "==> Copying $PACKAGE_NAME to $HOST"
scp "$PACKAGE" "$HOST:$REMOTE_PACKAGE"

echo "==> Replacing installed IPScope package on Unraid"
ssh "$HOST" "bash -s -- '$REMOTE_PACKAGE'" <<'REMOTE'
set -euo pipefail
PACKAGE_PATH="$1"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run deployment as root (normally root@Unraid)." >&2
  exit 1
fi
if [[ ! -f "$PACKAGE_PATH" ]]; then
  echo "Uploaded package not found: $PACKAGE_PATH" >&2
  exit 1
fi

# upgradepkg replaces the previous IPScope package in-place. The runtime
# snapshot under /var/local/emhttp/plugins/ipscope is not part of this package.
upgradepkg --install-new "$PACKAGE_PATH"
rm -f "$PACKAGE_PATH"

echo "IPScope package replacement completed."
REMOTE

echo
echo "Installed IPScope $VERSION on $HOST."
echo "Refresh Settings > User Utilities > IPScope with Ctrl+F5."
