# XionHosting Reseller für WHMCS

Version 1.0.0 · Stand 16.10.2026 · getestet gegen die Reseller-API Version 2 · [English](README.en.md)

Mit dem Modul verkaufst du KVM-Server aus deinem XionHosting-Reseller-Konto über WHMCS. WHMCS bestellt, sperrt, ändert und kündigt die Server bei uns. Deinem Kunden stellst du in WHMCS Rechnungen nach deinen eigenen Preisen. Wir rechnen mit dir stündlich nach Ressourcen ab, siehe [Reseller-API](https://xionhosting.com/cp/reseller).

## Voraussetzungen

- WHMCS 8.x mit PHP 7.4 oder neuer und der Erweiterung curl
- ein freigeschaltetes Reseller-Konto bei XionHosting
- ein API-Schlüssel (`xr_…`) aus dem Panel unter **Reseller → API**

## Installation

**Mit dem Installationsskript** (eine Datei, enthält alles):

```bash
curl -fsSLo install-xionreseller-whmcs.sh https://github.com/echtAbgezockt/XionHosting_WHMCs_Reselling/releases/latest/download/install-xionreseller-whmcs.sh
sudo bash install-xionreseller-whmcs.sh /var/www/whmcs
```

**Aus dem Repository:**

```bash
git clone https://github.com/echtAbgezockt/XionHosting_WHMCs_Reselling.git
cd XionHosting_WHMCs_Reselling
sudo bash install.sh /var/www/whmcs
```

Ohne Pfad sucht das Skript WHMCS unter `/var/www/whmcs`, `/var/www/html/whmcs`, `/var/www/html` und `/home/*/public_html`. Es prüft die PHP-Dateien, verschiebt eine vorhandene Fassung nach `/var/backups/xionreseller-<zeit>` und kopiert das Modul. Einstellungen und Datenbank bleiben unberührt.

**Von Hand:** den Ordner `modules/servers/xionreseller` in dein WHMCS-Verzeichnis kopieren, sodass `…/modules/servers/xionreseller/xionreseller.php` existiert.

### In WHMCS einrichten

1. **System Settings → Servers → Add New Server**:

   | Feld | Wert |
   |---|---|
   | Name | XionHosting |
   | Hostname | `api.xionhosting.com` |
   | Module | XionHosting Reseller (KVM) |
   | Password | dein API-Schlüssel `xr_…` |
   | Secure | an |

   **Test Connection** prüft Schlüssel und Kontostatus.
2. Eine Servergruppe mit diesem Server anlegen.
3. Produkt anlegen, unter **Module Settings** das Modul und die Servergruppe wählen.

Die Tabelle `mod_xionreseller` legt das Modul beim ersten Aufruf selbst an.

## Produkteinstellungen

| Feld | Bedeutung |
|---|---|
| vCores | 1 bis 16 |
| RAM (GB) | 1 bis 64 |
| Speicher (GB) | 10 bis 400, Vielfaches von 10 |
| IPv4-Adressen | 1 bis 8 |
| Betriebssystem | Auswahl aus deinem Xion-Konto, lädt live |
| Bezeichnung bei Xion | erscheint in deinem Xion-Panel, Platzhalter `{serviceid}` `{clientid}` `{domain}` `{email}` |
| Auf Bereitstellung warten (s) | Vorgabe 90. Bis dahin wartet die Erstellung auf IP und Passwort, damit beides in der Willkommensmail steht |
| Mailvorlage „Server bereit“ | Name einer Produkt-Mailvorlage für Server, die erst nach der Wartezeit fertig werden. Leer heißt keine Mail |
| Kunde darf neu installieren | Formular im Kundenbereich |
| Kunde darf Konsole öffnen | noVNC im Kundenbereich |

## Konfigurierbare Optionen

Willst du Größen zur Wahl stellen, lege konfigurierbare Optionen an. Der Name vor dem `|` entscheidet, Groß- und Kleinschreibung zählt nicht. Ein gesetzter Wert schlägt die Produkteinstellung.

| Ressource | erkannte Namen | Optionstyp |
|---|---|---|
| vCores | `vcores`, `cpu`, `cores` | Menge oder Auswahl |
| RAM in GB | `ram_gb`, `ram`, `memory`, `arbeitsspeicher` | Menge oder Auswahl |
| Speicher in GB | `disk_gb`, `disk`, `storage`, `speicher`, `ssd`, `nvme` | Menge oder Auswahl |
| IPv4 | `ipv4_count`, `ipv4`, `ips` | Menge |
| Betriebssystem | `os`, `template`, `betriebssystem` | Auswahl |

Bei einer Auswahl steht der Wert vor dem `|` der Unteroption, etwa `8|8 GB RAM`. Beim Betriebssystem nimmt das Modul die Template-ID (`9|Debian 12`), den Slug (`debian-12|Debian 12`) oder den Namen. Die IDs liefert `GET /v1/reseller/templates`.

## Was das Modul bei uns auslöst

| WHMCS | XionHosting | Hinweis |
|---|---|---|
| Create | `POST /servers` | Bereitstellung dauert meist ein bis drei Minuten. Scheitert sie, kostet der Server nichts |
| Suspend | `POST /servers/:id/lock` | VM wird gestoppt, die Abrechnung bei uns läuft weiter |
| Unsuspend | `POST /servers/:id/unlock` | hebt nur deine eigene Sperre auf. Eine Sperre durch uns (Abuse, Verzug) bleibt, das Modul meldet den Grund |
| Terminate | `DELETE /servers/:id` | VM und Daten sind sofort weg. Ab der nächsten vollen Stunde zahlst du nichts mehr |
| Change Package | Erweiterungen | vCores und RAM gehen in beide Richtungen und greifen nach dem nächsten Neustart. Speicher geht nur nach oben. Die Zahl der IPv4 bleibt fest |

Admin-Knöpfe: Starten, Herunterfahren, Neustart, Hart ausschalten, Neues Root-Passwort, Neu installieren (mit dem aktuellen Betriebssystem), Mit Xion abgleichen.

Kundenbereich: Zustand, IPs, Zugangsdaten, Ein- und Ausschalten, neues Root-Passwort, Konsole, Reverse DNS je IPv4 und Neuinstallation mit Wahl des Betriebssystems. Die Neuinstallation verlangt einen Haken als Bestätigung.

## Abgleich per Cron

`hooks.php` hängt sich an den WHMCS-Cron:

- nach jedem Cron-Lauf: Server in Einrichtung abfragen. Sobald einer läuft, landen IP, Benutzer und Passwort im Dienst und die Mailvorlage „Server bereit“ geht raus, falls eingetragen.
- einmal am Tag: alle verknüpften Server abgleichen, damit Sperren und IPs von unserer Seite in WHMCS ankommen.

Ergebnisse stehen im Activity Log mit dem Präfix „Xion-Reseller“.

## Bestehende Server übernehmen

Im Dienst unter **Products/Services** steht das Feld **Xion-Server-ID**. Dort die ID, UUID oder den Bezeichner (`SRV-13046`) eintragen und speichern. Das Modul holt IP und Zugangsdaten und schickt keine Bereit-Mail. Leeres Feld speichern löst die Verknüpfung, der Server bei uns bleibt dabei unberührt.

## Fehlermeldungen

| Meldung beginnt mit | Ursache |
|---|---|
| API-Schlüssel ungültig | Schlüssel falsch eingetragen oder im Panel widerrufen |
| Freibetrag bei Xion reicht nicht | dein Kreditrahmen ist ausgeschöpft oder eine Rechnung ist überfällig |
| Bei Xion ist gerade kein Platz frei | kein Node hat genug RAM oder Speicher, später erneut versuchen |
| Zu viele Anfragen | mehr als 20 Bestellungen oder 120 Änderungen je Minute |
| Der Server ist von XionHosting gesperrt | Abuse oder Verzug, das klärt unser Support |

Jeder API-Aufruf steht im Modulprotokoll (**System Logs → Module Log**). Schlüssel und Passwörter sind dort geschwärzt.

## Sicherheit

- Trag den Schlüssel ins Feld Password ein, das speichert WHMCS verschlüsselt. Das Feld Access Hash geht auch und hat Vorrang, falls beide gefüllt sind. Gib dem Schlüssel im Xion-Panel einen eigenen Namen, dann siehst du dort letzte Nutzung und IP.
- Ein Schlüssel kann keine weiteren Schlüssel erzeugen und nichts außerhalb des Reseller-Bereichs.
- Die Konsole öffnet der Browser deines Kunden direkt bei uns. Die Sitzung gilt 55 Sekunden für den Verbindungsaufbau und nur für diesen einen Server.

## Dateien

```
modules/servers/xionreseller/
  xionreseller.php        Modulfunktionen
  hooks.php               Cron-Abgleich
  lib/Client.php          HTTP-Client
  lib/Service.php         Verknüpfung und Übernahme nach tblhosting
  lib/functions.php       gemeinsame Hilfen
  templates/clientarea.tpl
  templates/console.tpl
install.sh                Installation aus dem Repository
tools/build-installer.sh  baut dist/install-xionreseller-whmcs.sh (eine Datei mit allem)
```

## Neue Version veröffentlichen

1. Versionsnummer in `lib/Client.php` (`const VERSION`) und oben in dieser Datei ändern, `CHANGELOG.md` ergänzen.
2. Hochladen und ein Release anlegen: auf GitHub unter **Releases → Draft a new release** einen neuen Tag wie `v1.0.1` eintragen und veröffentlichen (mit Git: `git tag v1.0.1 && git push --tags`).
3. GitHub Actions prüft alle PHP-Dateien (PHP 7.4, 8.1, 8.3), baut das Installationsskript, installiert es einmal zur Probe und hängt es an das Release.

## Lizenz

MIT, siehe [LICENSE](LICENSE). Du darfst das Modul nutzen, ändern und weitergeben, auch die Vorlagen unter `templates/` für dein eigenes Design. Der Hinweis auf das Copyright muss erhalten bleiben.

Tipp für eigene Anpassungen: Änderungen direkt im Modulordner gehen beim nächsten Update verloren, weil die Installation den alten Ordner wegschiebt. Heb deine angepassten Vorlagen getrennt auf.
