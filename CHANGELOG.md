# Changelog

Alle Versionen des Konzertplaners. Installierte Versionen aktualisieren sich über das eingebaute Update-System (Hauptseite → „Auf Updates prüfen“).

## 1.8.6 — 15.06.2026
- Sicherheits-Härtung vor der Open-Source-Veröffentlichung: Der Login ist jetzt zusätzlich gegen automatisiertes Passwort-Raten geschützt (zeitlich gestaffelte Sperre nach mehreren Fehlversuchen). Interne Schutzdateien liegen nun im geschützten Konfigurations-Ordner, und die Schutzregeln der Upload-Ordner werden bei Bedarf automatisch wiederhergestellt. Für die normale Nutzung ändert sich nichts.

## 1.8.5 — 14.06.2026
- Fehler behoben: Beim Übernehmen eines älteren Konzerts (aus einer früheren Version) bleiben die Track-Zuordnungen in den Slots (z. B. Original und Live) jetzt erhalten — vorher konnten sie beim ersten Speichern verloren gehen.

## 1.8.4 — 13.06.2026
- Der Bearbeiten-Knopf oben rechts sieht jetzt in beiden Zuständen identisch aus (durchgängig grün) — nur die Beschriftung wechselt zwischen „Bearbeiten" und „Bearbeiten beenden". Zusätzlich besser lesbar dank dunkler Schrift auf dem Grün.

## 1.8.3 — 13.06.2026
- Barrierefreiheit: Die Ränder von + Track, + Text, dem Bearbeiten-Knopf, den Einstellungs-Abschnitten und der Freigabe-Box sind jetzt klar sichtbar (erfüllen den Kontrast-Standard für Bedien-Elemente). Außerdem haben die Icon-Knöpfe eindeutige Bezeichnungen für Screenreader.

## 1.8.2 — 13.06.2026
- Aufgeräumte Bedienung: Die Knöpfe oben sind jetzt klar als Buttons erkennbar und einheitlich gestaltet — Einstellungen (mit Zahnrad), + Track, + Text und Bearbeiten beenden. Das Einstellungs-Fenster ist übersichtlich in klare Abschnitte gegliedert (Eckdaten, Probetermine, Track-Slots, Marker, Freigabe), und die Freigabe-Option ist deutlich hervorgehoben.

## 1.8.1 — 13.06.2026
- Feinschliff am Slot-Editor: bessere Bedienbarkeit mit Tastatur und Screenreader (deutsche Farbnamen, erkennbarer Auswahl-Status, etwas größere Farbflächen).

## 1.8.0 — 13.06.2026
- Konfigurierbare Track-Slots: Statt fest Original/Live legst du jetzt in den Konzert-Angaben 1 bis 5 eigene Slots an — jeder mit frei wählbarem Namen und Farbe (als Punkt am Slot sichtbar). Die Reihenfolge bestimmt die Priorität für die Laufzeit, und die Auto-Play-Auswahl zeigt deine Slots zur Wahl. Neue Konzerte starten schlank mit einem Slot; bestehende Konzerte behalten automatisch ihre beiden Slots Original und Live.

## 1.7.4 — 13.06.2026
- Feinschliff am Bearbeitungsmodus: Der Hinweis in leeren Track-Slots erscheint jetzt eindeutig (nicht mehr doppelt) und das „Live"-Etikett ist besser lesbar.

## 1.7.3 — 13.06.2026
- Übersichtlicherer Bearbeitungsmodus: klarere Original-/Live-Track-Slots (belegte Slots wirken „fertig", leere zeigen eine deutliche Ablage mit Hinweis), aufgeräumte Eintrags-Knöpfe (Kopieren/Löschen gebündelt) und insgesamt mehr Ruhe und Struktur im Layout.

## 1.7.2 — 13.06.2026
- Einheitliche Buttons: Knöpfe haben jetzt überall dieselbe Form und Größe wie auf der Webseite und im Konto-Bereich — ein gemeinsames, ruhigeres Erscheinungsbild.

## 1.7.1 — 13.06.2026
- Barrierefreiheit: Buttons in einem etwas tieferen Blau, damit die weiße Schrift den Kontrast-Standard sicher erfüllt. Rein optischer Feinschliff.

## 1.7.0 — 13.06.2026
- Neues, durchgängig dunkles Erscheinungsbild „Konzertplaner Dark": tiefes Navy, Marken-Blau und die Wellen-Marke. Webseite, Konto-Bereich und Planer wirken jetzt wie aus einem Guss. Funktional ändert sich nichts.

## 1.6.1 — 13.06.2026
- Sicherheits-Verbesserungen: strengere Prüfung beim Hochladen von Tracks (Dateien werden jetzt immer auf ihren echten Typ geprüft) und zusätzlicher Schutz für freigegebene Konzerte. Für die normale Nutzung ändert sich nichts.

## 1.6.0 — 13.06.2026
- Neues, durchgängiges Erscheinungsbild: Der Planer trägt jetzt dasselbe helle Design wie Webseite und Konto-Bereich — Marken-Blau, Überschriften in Navy und der Schrift Raleway, einheitlicher heller Hintergrund statt der dunklen Oberfläche. Webseite, Konto-Bereich und Planer wirken jetzt wie aus einem Guss.

## 1.5.0 — 13.06.2026
- Schönere Hinweise: Rückfragen und Meldungen (z. B. beim Löschen, Duplizieren, Track-Entfernen oder beim Update) erscheinen jetzt in einem einheitlichen, zur Oberfläche passenden Popup-Fenster statt in den schlichten Browser-Dialogen. Mit Esc abbrechen, mit Enter bestätigen, Klick neben das Fenster schließt es.

## 1.4.1 — 13.06.2026
- Wichtige Fehlerbehebung: Die Konzert-Detailseite (Audio-Player, Bearbeiten, Drag&Drop, Marker) lud nach dem letzten Umbau nicht mehr, weil der Modul-Pfad zum Player kein gültiger Browser-Pfad mehr war. Jetzt behoben — alles funktioniert wieder.
- Der Konzertplaner steht ab sofort offiziell unter der MIT-Lizenz (Datei LICENSE im Paket).

## 1.4.0 — 12.06.2026
- Frischer Look: neues freistehendes Wellen-Logo mit zwei Markern und eine dunkelblaue Oberfläche, die das Tool sichtbar mit der Konzertplaner-Webseite verbindet. Funktional ändert sich nichts.

## 1.3.0 — 12.06.2026
- Neu: Konzerte freigeben! In den Konzert-Angaben kannst du jetzt eine Freigabe aktivieren: Bandkollegen sehen das Programm über einen geheimen Link plus Passwort — schreibgeschützt und ohne eigenen Account. Der Link wandert beim Aktivieren einer anderen Version automatisch mit.
- Lizenzhinweis für den eingebauten Audio-Player (wavesurfer.js, BSD-3-Clause) ergänzt — neue Datei LIZENZEN.txt.

## 1.2.0 — 12.06.2026
- Plattform-Integration verbessert: Die Online-Version auf konzertplaner.brosemedien.de zeigt jetzt eine durchgängige Kopfleiste mit Navigation. Für Selfhosting-Installationen ändert sich nichts.

## 1.1.1 — 12.06.2026
- Sicherheits-Härtung: Beim Löschen von Tracks werden nur noch MP3-Dateien akzeptiert.

## 1.1.0 — 12.06.2026
- Plattform-Modus erweitert: Navigations-Hook für die Online-Version auf konzertplaner.brosemedien.de und konfigurierbarer Zurück-Link. Für Selfhosting-Installationen ändert sich nichts.

## 1.0.1 — 12.06.2026
- Neues Logo und Favicon (Marker-Welle) — der Konzertplaner hat jetzt ein Gesicht.

## 1.0.0 — 12.06.2026
- Erste veröffentlichte Version: Konzertverwaltung mit Drag&Drop-Programm, Dual-Slot-System (Original/Live), Track-Pool, Wavesurfer-Player mit Markern, Notenupload, Probeterminen, Backup-Versionen und Selbst-Update.

