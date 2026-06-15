KONZERTPLANER
=============

Webbasierte Konzertplanung mit Drag&Drop-Programm, Audio-Player mit
Wellenform und Markern, Notenverwaltung und Probeterminen.

https://konzertplaner.brosemedien.de


SYSTEMVORAUSSETZUNGEN
---------------------
- PHP 8.0 oder neuer (mit den Standard-Erweiterungen json und zip)
- Apache-Webserver mit .htaccess-Unterstützung (üblicher Shared-Webspace reicht)
- Keine Datenbank nötig — alle Daten liegen als Dateien auf deinem Webspace


INSTALLATION IN 3 SCHRITTEN
---------------------------
1. ZIP entpacken und den kompletten Inhalt in ein Verzeichnis auf
   deinem Webspace hochladen (z. B. /konzertplaner/).
2. Das Verzeichnis im Browser aufrufen.
3. Im Einrichtungsdialog ein Passwort festlegen — fertig.

Wichtig: Die Ordner config/ und daten/ brauchen Schreibrechte
(bei den meisten Hostern automatisch der Fall).


ORDNER-ÜBERSICHT
----------------
index.php      Einstiegspunkt
core/          Programmcode — wird bei Updates ersetzt
config/        deine Konfiguration (Passwort) — bleibt bei Updates erhalten
daten/         deine Konzerte, Tracks, Noten — bleiben bei Updates erhalten
version.json   installierte Version


UPDATES
-------
Auf der Hauptseite unten findest du "Auf Updates prüfen". Ein Klick
holt die neueste Version von konzertplaner.brosemedien.de, legt vorher
automatisch ein Backup an und ersetzt nur den Programmcode — deine
Konzerte, Tracks, Noten und dein Passwort bleiben unangetastet.


LIZENZ
------
Der Konzertplaner steht unter der MIT-Lizenz — frei nutzen, anpassen
und weitergeben, auch kommerziell. Einzige Bedingung: Der Lizenztext
in der Datei LICENSE bleibt erhalten.


FREMDSOFTWARE
-------------
Der Audio-Player basiert auf wavesurfer.js (BSD-3-Clause-Lizenz,
Copyright katspaugh and contributors) — Details in LIZENZEN.txt.
Die Datei LIZENZEN.txt muss bei Weitergabe erhalten bleiben.


PASSWORT VERGESSEN?
-------------------
Lösche die Datei config/config.php auf dem Webspace. Beim nächsten
Aufruf erscheint wieder der Einrichtungsdialog und du kannst ein
neues Passwort festlegen. Deine Daten bleiben erhalten.
