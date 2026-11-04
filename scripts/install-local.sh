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
if [[ ! -s "$PACKAGE" ]]; then
  echo "Package was not created or is empty: $PACKAGE" >&2
  exit 1
fi

PACKAGE_CONTENTS="$(tar -tJf "$PACKAGE")"
grep -Fxq './usr/local/emhttp/plugins/ipscope/IPScope.page' <<< "$PACKAGE_CONTENTS"
grep -Fxq './usr/local/emhttp/plugins/ipscope/api.php' <<< "$PACKAGE_CONTENTS"
grep -Fxq './usr/local/emhttp/plugins/ipscope/images/ipscope.png' <<< "$PACKAGE_CONTENTS"


echo "==> Installing/replacing IPScope locally"
echo "Package: $PACKAGE"
upgradepkg --install-new "$PACKAGE"

if [[ ! -s /usr/local/emhttp/plugins/ipscope/images/ipscope.png ]]; then
  echo "Installed IPScope launcher icon is missing." >&2
  exit 1
fi

# The launch target belongs to the installed PLG manifest, not the Slackware
# package payload. Local package upgrades do not replace that manifest, so
# update only its navigation metadata while preserving release URLs/checksums.
PLUGIN_MANIFEST="/boot/config/plugins/ipscope.plg"
if [[ -f "$PLUGIN_MANIFEST" ]]; then
  php -r '
    $path = $argv[1];
    $xml = file_get_contents($path);
    if ($xml === false) {
      fwrite(STDERR, "Unable to read installed plugin manifest.\\n");
      exit(1);
    }
    $count = 0;
    $updated = preg_replace_callback("/<PLUGIN\\b[^>]*>/s", function ($match) {
      $tag = $match[0];
      if (preg_match("/\\blaunch\\s*=\\s*[\"\\x27][^\"\\x27]*[\"\\x27]/", $tag)) {
        $tag = preg_replace("/\\blaunch\\s*=\\s*([\"\\x27])[^\"\\x27]*\\1/", "launch=\"Settings/IPScope\"", $tag, 1);
      } else {
        $tag = preg_replace("/>$/", " launch=\"Settings/IPScope\">", $tag);
      }
      if (preg_match("/\\bicon\\s*=\\s*[\"\\x27][^\"\\x27]*[\"\\x27]/", $tag)) {
        $tag = preg_replace("/\\bicon\\s*=\\s*([\"\\x27])[^\"\\x27]*\\1/", "icon=\"images/ipscope.png\"", $tag, 1);
      } else {
        $tag = preg_replace("/>$/", " icon=\"images/ipscope.png\">", $tag);
      }
      return $tag;
    }, $xml, 1, $count);
    if ($count !== 1 || $updated === null || file_put_contents($path, $updated) === false) {
      fwrite(STDERR, "Unable to update IPScope launch metadata in installed PLG.\\n");
      exit(1);
    }
  ' "$PLUGIN_MANIFEST"
  php -r '
    $xml = simplexml_load_file($argv[1]);
    if ($xml === false || (string)$xml["launch"] !== "Settings/IPScope" || (string)$xml["icon"] !== "images/ipscope.png") {
      fwrite(STDERR, "Installed PLG metadata verification failed.\\n");
      exit(1);
    }
  ' "$PLUGIN_MANIFEST"
  echo "Updated installed plugin metadata: launch=Settings/IPScope icon=images/ipscope.png"
else
  echo "Warning: $PLUGIN_MANIFEST not found; plugin-list launch target was not updated." >&2
fi

echo
echo "IPScope $VERSION installation completed."
echo "Open Settings > User Utilities > IPScope."
echo "If the page is already open, refresh it with Ctrl+F5."
