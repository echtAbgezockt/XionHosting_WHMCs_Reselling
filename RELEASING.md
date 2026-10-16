# Neue Version veröffentlichen

1. Versionsnummer in `lib/Client.php` (`const VERSION`) und in der ersten Zeile von `README.md` und `README.en.md` ändern, `CHANGELOG.md` ergänzen.
2. Hochladen und ein Release anlegen: auf GitHub unter **Releases → Draft a new release** einen neuen Tag wie `v1.0.1` eintragen und veröffentlichen (mit Git: `git tag v1.0.1 && git push --tags`).
3. GitHub Actions prüft alle PHP-Dateien (PHP 7.4, 8.1, 8.3), baut das Installationsskript, installiert es einmal zur Probe und hängt es an das Release.

Nur für die Pflege dieses Repositorys, nicht für Reseller.
