<div align="center">
  <img src="docs/banner.png" alt="Konzertplaner" width="640">
  <h1>Konzertplaner</h1>
  <p><strong>Plant euer Konzert. Gemeinsam.</strong></p>
  <p>
    Webbasierte Konzertplanung: Songs per Drag&amp;Drop ordnen, Tracks mit
    Wellenform und Zeit-Markern anhören, Noten und Probetermine verwalten —
    und das fertige Programm per Link mit der Band teilen.
  </p>
  <p>
    🌐 <a href="https://konzertplaner.brosemedien.de">konzertplaner.brosemedien.de</a>
    &nbsp;·&nbsp; 📖 <a href="https://konzertplaner.brosemedien.de/faq#anleitung">Anleitung</a>
    &nbsp;·&nbsp; 🆓 MIT-Lizenz
  </p>
</div>

---

Der Konzertplaner ist aus der Praxis entstanden — ein echtes Konzert, ein echtes
Programm, echte Proben. Er läuft als schlanke **PHP-Anwendung ohne Datenbank** auf
jedem üblichen Webspace: alle Daten liegen als Dateien bei dir.

## Funktionen

- **Programm-Editor** mit Drag&amp;Drop, Abschnitten und live mitlaufender Gesamtlaufzeit
- **Konfigurierbare Track-Slots** — 1 bis 5 frei benannte Spuren pro Song (z. B. Original & Live)
- **Audio-Player** mit Wellenform und farbigen **Zeit-Markern** (inkl. 10-Sekunden-Vorwarnung)
- **Notenverwaltung** — PDF/Bild direkt am Song, Vorschau auf der Seite
- **Probetermine** und **Backup-Versionen** je Konzert
- **Freigabe per Link + Passwort** — die Band sieht und hört alles schreibgeschützt, ganz ohne eigenen Account
- **Eingebautes Update-System** mit automatischem Backup und Rollback

## Systemvoraussetzungen

- PHP 8.1 oder neuer (Standard-Erweiterungen `json` und `zip`)
- Apache-Webserver mit `.htaccess`-Unterstützung — ein üblicher Shared-Webspace reicht
- **Keine Datenbank** nötig
- Schreibrechte für die Ordner `config/` und `daten/` (bei den meisten Hostern Standard)

## Installation in 3 Schritten

1. Dieses Repository herunterladen (oder das [ZIP-Paket](https://konzertplaner.brosemedien.de/download)) und entpacken.
2. Den kompletten Inhalt in ein Verzeichnis auf deinem Webspace laden, z. B. `/konzertplaner/`.
3. Das Verzeichnis im Browser aufrufen und im Einrichtungsdialog ein Passwort festlegen — fertig.

Zum Üben ist ein Beispielkonzert hinterlegt. Eine ausführliche, bebilderte
Schritt-für-Schritt-Anleitung gibt es auf der
[Projekt-Webseite](https://konzertplaner.brosemedien.de/faq#anleitung).

## Ordnerstruktur

| Ordner / Datei | Zweck |
|---|---|
| `index.php`    | Einstiegspunkt (Pfade & Betriebsart, lädt `core/`) |
| `core/`        | Programmcode — wird bei Updates ersetzt |
| `config/`      | deine Konfiguration (Passwort) — bleibt bei Updates erhalten |
| `daten/`       | deine Konzerte, Tracks, Noten — bleiben bei Updates erhalten |
| `version.json` | installierte Version + Changelog |

## Updates

Auf der Hauptseite findest du **„Auf Updates prüfen"**. Ein Klick holt die
neueste Version von [konzertplaner.brosemedien.de](https://konzertplaner.brosemedien.de),
legt vorher automatisch ein Backup an und ersetzt **nur** den Programmcode —
deine Konzerte, Tracks, Noten und dein Passwort bleiben unangetastet.

## Passwort vergessen?

Lösche die Datei `config/config.php` auf dem Webspace. Beim nächsten Aufruf
erscheint wieder der Einrichtungsdialog. Deine Daten bleiben erhalten.

## Sicherheit

Der Konzertplaner ist für den Betrieb auf öffentlichen Webspaces ausgelegt:
strenge Validierung von IDs und Dateinamen, serverseitige MIME-Prüfung bei
Uploads, kein Skript-Ausführen in den Upload-Ordnern (`.htaccess`),
gehärtete Sessions, bcrypt-Passwörter, gestaffelte Sperren gegen
Passwort-Raten und ein gegen ZIP-Slip abgesichertes, signaturgeprüftes
Update über HTTPS. Sicherheitslücken bitte vertraulich melden (Kontakt über
die [Projekt-Webseite](https://konzertplaner.brosemedien.de)).

> **Hinweis:** Für Linux/Apache-Webspaces entwickelt und getestet. Windows-Hosting wird nicht offiziell unterstützt.

## Lizenz

[MIT](LICENSE) — frei nutzen, anpassen und weitergeben, auch kommerziell.
Einzige Bedingung: Der Lizenztext in der Datei `LICENSE` bleibt erhalten.

Der eingebaute Audio-Player basiert auf
[wavesurfer.js](https://github.com/katspaugh/wavesurfer.js) (BSD-3-Clause) —
Details in [`LIZENZEN.txt`](LIZENZEN.txt).

---

<div align="center">
  <sub>Ein Projekt von <a href="https://konzertplaner.brosemedien.de">BroseMedien</a></sub>
</div>
