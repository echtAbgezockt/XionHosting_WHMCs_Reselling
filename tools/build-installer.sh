#!/usr/bin/env bash
# shellcheck disable=SC1111  # deutsche Anführungszeichen „…“ im Text sind gewollt
# Baut dist/install-xionreseller-whmcs.sh: ein einziges Skript mit allen Dateien darin,
# zum Herunterladen per curl (Release-Datei auf GitHub oder eigener Downloadserver).
#
#   bash tools/build-installer.sh
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
VERSION="$(sed -n "s/.*const VERSION = '\([^']*\)'.*/\1/p" modules/servers/xionreseller/lib/Client.php | head -n 1)"
[ -n "$VERSION" ] || { echo "Version nicht gefunden (lib/Client.php, const VERSION)." >&2; exit 1; }
mkdir -p dist
OUT="dist/install-xionreseller-whmcs.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
# Feste Zeitstempel und Besitzer: gleicher Stand ergibt dieselbe Datei.
tar --sort=name --mtime='2026-01-01 00:00:00Z' --owner=0 --group=0 --numeric-owner --mode='u+rwX,go+rX,go-w' \
    -czf "$TMP/paket.tgz" install.sh README.md LICENSE modules
{
  printf '%s\n' '#!/usr/bin/env bash' \
    "# Installiert das WHMCS-Modul „XionHosting Reseller (KVM)“ ${VERSION}." \
    '# Aufruf: sudo bash install-xionreseller-whmcs.sh /pfad/zu/whmcs' \
    '# Quelle: install.sh und modules/ aus dem Repository, hier als Archiv eingepackt.' \
    'set -euo pipefail' \
    'TMP="$(mktemp -d)"' \
    "trap 'rm -rf \"\$TMP\"' EXIT" \
    "base64 -d > \"\$TMP/paket.tgz\" <<'PAKET'"
  base64 -w 0 "$TMP/paket.tgz"; printf '\n'
  printf '%s\n' 'PAKET' \
    'tar xzf "$TMP/paket.tgz" -C "$TMP"' \
    'XR_SOURCE="$TMP" bash "$TMP/install.sh" "$@"'
} > "$OUT"
chmod 755 "$OUT"
echo "$OUT ($(wc -c < "$OUT") Bytes, Version $VERSION)"
sha256sum "$OUT"
