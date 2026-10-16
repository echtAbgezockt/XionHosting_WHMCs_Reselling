#!/usr/bin/env bash
# shellcheck disable=SC1111  # deutsche Anführungszeichen „…“ im Text sind gewollt
# Installiert das WHMCS-Modul „XionHosting Reseller (KVM)“ aus diesem Ordner.
#
#   git clone https://github.com/<konto>/xionreseller-whmcs.git
#   cd xionreseller-whmcs
#   sudo bash install.sh /pfad/zu/whmcs     (ohne Pfad sucht das Skript an den üblichen Stellen)
#
# Das Skript kopiert nur modules/servers/xionreseller nach WHMCS. Es ändert keine Einstellungen und keine
# Datenbank. Eine vorhandene Fassung wird vorher nach /var/backups/xionreseller-<zeit> verschoben.
set -euo pipefail

QUELLE="${XR_SOURCE:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"
[ -f "$QUELLE/modules/servers/xionreseller/xionreseller.php" ] || { echo "Modul nicht gefunden in $QUELLE/modules/servers/xionreseller." >&2; exit 1; }
VERSION="$(sed -n "s/.*const VERSION = '\([^']*\)'.*/\1/p" "$QUELLE/modules/servers/xionreseller/lib/Client.php" | head -n 1)"
echo "XionHosting Reseller (KVM) ${VERSION:-?}"

WHMCS="${1:-}"
if [ -z "$WHMCS" ]; then
  for d in /var/www/whmcs /var/www/html/whmcs /var/www/html /home/*/public_html; do
    [ -f "$d/configuration.php" ] && [ -d "$d/modules/servers" ] && WHMCS="$d" && break
  done
fi
if [ -z "$WHMCS" ] || [ ! -f "$WHMCS/configuration.php" ] || [ ! -d "$WHMCS/modules/servers" ]; then
  echo "WHMCS nicht gefunden. Aufruf: bash $0 /pfad/zu/whmcs" >&2
  exit 1
fi
WHMCS="$(cd "$WHMCS" && pwd)"
ZIEL="$WHMCS/modules/servers/xionreseller"

PHP="$(command -v php || true)"
if [ -n "$PHP" ]; then
  "$PHP" -r 'exit(version_compare(PHP_VERSION, "7.4", ">=") ? 0 : 1);' || { echo "PHP 7.4 oder neuer nötig." >&2; exit 1; }
  "$PHP" -m | grep -qi '^curl$' || echo "Warnung: PHP-Erweiterung curl fehlt auf der Kommandozeile. Im Webserver-PHP muss sie aktiv sein."
else
  echo "Hinweis: kein php im PATH, Versionsprüfung übersprungen."
fi

# Erst prüfen, dann tauschen: Mit einem Syntaxfehler würde WHMCS die Seiten nicht mehr laden.
if [ -n "$PHP" ]; then
  for f in "$QUELLE"/modules/servers/xionreseller/xionreseller.php "$QUELLE"/modules/servers/xionreseller/hooks.php "$QUELLE"/modules/servers/xionreseller/lib/*.php; do
    "$PHP" -l "$f" >/dev/null || { echo "Syntaxfehler in $f - nichts installiert." >&2; exit 1; }
  done
fi

if [ -d "$ZIEL" ]; then
  # Sicherung außerhalb von modules/: WHMCS lädt jede hooks.php dort, eine
  # alte Kopie würde die Funktionen doppelt deklarieren.
  SICHERUNG="${XR_BACKUP_DIR:-/var/backups}/xionreseller-$(date +%Y%m%d-%H%M%S)"
  mkdir -p "$(dirname "$SICHERUNG")"
  mv "$ZIEL" "$SICHERUNG"
  echo "Alte Version gesichert: $SICHERUNG"
fi
cp -r "$QUELLE/modules/servers/xionreseller" "$ZIEL"
cp "$QUELLE/README.md" "$ZIEL/README.md"
[ ! -f "$QUELLE/LICENSE" ] || cp "$QUELLE/LICENSE" "$ZIEL/LICENSE"

# Besitzer wie der WHMCS-Ordner, Rechte 755/644
chown -R --reference="$WHMCS/modules/servers" "$ZIEL" 2>/dev/null || true
find "$ZIEL" -type d -exec chmod 755 {} +
find "$ZIEL" -type f -exec chmod 644 {} +

cat <<TXT

Installiert nach: $ZIEL

Weiter in WHMCS:
  1. System Settings → Servers → Add New Server
       Hostname: api.xionhosting.com
       Module:   XionHosting Reseller (KVM)
       Password: dein API-Schlüssel xr_…
       Secure:   an
     → „Test Connection“ muss grün sein.
  2. Servergruppe mit diesem Server anlegen.
  3. Produkt anlegen → Module Settings → Modul + Gruppe wählen.

Anleitung: $ZIEL/README.md
TXT
