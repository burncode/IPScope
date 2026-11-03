#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-}"
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][A-Za-z0-9.-]+)?$ ]]; then
  echo "Usage: $0 <version> (example: $0 0.1.0)" >&2
  exit 2
fi

for cmd in php tar xz md5sum sha256sum; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "Missing required command: $cmd" >&2; exit 1; }
done

"$ROOT/scripts/check-local.sh"

STAGE="$ROOT/dist/stage"
PKGNAME="ipscope-$VERSION-noarch-1"
PKG="$ROOT/dist/$PKGNAME.txz"
PLG="$ROOT/dist/ipscope.plg"
BASE="${IPSCOPE_RELEASE_BASE_URL:-https://github.com/burncode/IPScope/releases/download/v$VERSION}"

rm -rf "$ROOT/dist"
mkdir -p "$STAGE/usr/local/emhttp/plugins/ipscope"

DEST="$STAGE/usr/local/emhttp/plugins/ipscope"
mkdir -p "$DEST/src"
cp -a "$ROOT/src/lib" "$DEST/src/lib"
cp -a "$ROOT/src/www" "$DEST/src/www"
cp -a "$ROOT/src/ipscope-scan.php" "$DEST/src/"

cp -a "$ROOT/src/ipscope-scan.php" "$DEST/"

# The page and API live at stable plugin URLs; implementation remains under src/www.
cp "$ROOT/packaging/IPScope.page" "$DEST/IPScope.page"
cp "$ROOT/packaging/api.php" "$DEST/api.php"
mkdir -p "$STAGE/install"
cat > "$STAGE/install/slack-desc" <<'EOF'
ipscope: IPScope (Unraid Docker IPv4 inventory)
ipscope:
ipscope: Read-only IPv4 address inventory for Unraid Docker networks.
ipscope: Tracks running container addresses and saved template reservations.
ipscope:
EOF
cat > "$STAGE/install/doinst.sh" <<'EOF'
#!/bin/sh
chmod 0755 /usr/local/emhttp/plugins/ipscope
EOF
chmod 0755 "$STAGE/install/doinst.sh"

mkdir -p "$ROOT/dist"
tar -C "$STAGE" -cJf "$PKG" .
MD5="$(md5sum "$PKG" | awk '{print $1}')"
SHA256="$(sha256sum "$PKG" | awk '{print $1}')"

cat > "$PLG" <<EOF
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
  <!ENTITY name "ipscope">
  <!ENTITY version "$VERSION">
  <!ENTITY packageName "$PKGNAME.txz">
  <!ENTITY packageURL "$BASE/$PKGNAME.txz">
  <!ENTITY packageMD5 "$MD5">
  <!ENTITY packageSHA256 "$SHA256">
  <!ENTITY cfgdir "/boot/config/plugins/ipscope">
]>
<PLUGIN name="&name;" author="burncode" version="&version;"
        pluginURL="https://github.com/burncode/IPScope/releases/download/v$VERSION/ipscope.plg"
        min="7.0.0" support="https://github.com/burncode/IPScope/issues" icon="network-wired">
  <CHANGES>
    ### $VERSION
    - Initial installable IPScope package.
    - Read-only Docker IPv4 inventory and saved-template reservations.
  </CHANGES>
  <FILE Name="&cfgdir;/&packageName;">
    <URL>&packageURL;</URL>
    <MD5>&packageMD5;</MD5>
  </FILE>
  <FILE Run="/bin/bash">
    <INLINE><![CDATA[
set -e
CFG="/boot/config/plugins/ipscope"
mkdir -p "$CFG"
PKGFILE="$(find "$CFG" -maxdepth 1 -type f -name "ipscope-*-noarch-1.txz" | sort | tail -n 1)"
[ -n "$PKGFILE" ] || { echo "IPScope package archive not found." >&2; exit 1; }
installpkg "$PKGFILE"
mkdir -p /var/local/emhttp/plugins/ipscope
chmod 0755 /usr/local/emhttp/plugins/ipscope
echo "IPScope &version; installed."
    ]]></INLINE>
  </FILE>
  <FILE Run="/bin/bash" Method="remove">
    <INLINE><![CDATA[
removepkg ipscope || true
rm -rf /usr/local/emhttp/plugins/ipscope
rm -rf /var/local/emhttp/plugins/ipscope
find /boot/config/plugins/ipscope -maxdepth 1 -type f -name "ipscope-*-noarch-1.txz" -delete
# Keep /boot/config/plugins/ipscope so user configuration survives uninstall.
    ]]></INLINE>
  </FILE>
</PLUGIN>
EOF

echo "Built:"
echo "  $PKG"
echo "  $PLG"
echo "MD5: $MD5"
echo "SHA256: $SHA256"
