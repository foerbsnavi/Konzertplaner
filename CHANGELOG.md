# Changelog

Alle Versionen des Konzertplaners. Installierte Versionen aktualisieren sich über das eingebaute Update-System (Hauptseite → „Auf Updates prüfen“).

## 1.13.1 — 10.07.2026
- Feinschliff zum 1.13.0-Update: Der Audio-Player-Baustein bekommt jetzt ebenfalls eine Versions-Kennung in der Adresse (verhindert, dass Browser nach künftigen Updates bis zu 30 Tage eine veraltete Player-Version aus dem Cache laden), Track-Dateien werden nur noch einen Tag zwischengespeichert (wer eine Datei unter gleichem Namen ersetzt, hört spätestens am Folgetag sicher die neue Fassung), und das Notenbild im Noten-Editor wird für Screenreader ausgeblendet (es ist rein visuell — der Notentext selbst bleibt voll zugänglich).

## 1.13.0 — 10.07.2026
- Der Player sagt jetzt Bescheid, wenn ein Track nicht geladen werden kann (z. B. Datei fehlt oder keine Berechtigung) — vorher passierte beim Klick auf Play einfach nichts. Bei aktivem Auto-Play wird der nächste abspielbare Track versucht.
- Fehler behoben: In der Freigabe-Ansicht mit Marker- oder Bearbeitungs-Recht wurden gesetzte Zeit-Marker nie gespeichert — die Anfrage wurde ohne Freigabe-Kennung geschickt und vom Server abgelehnt. Marker von Bandkollegen kommen jetzt an.
- Deutlich robusterer Schutz vor gegenseitigem Überschreiben: Alle Speichervorgänge desselben Konzerts (Programm, Eckdaten, Track-Längen, Duplizieren, Freigabe-Einstellungen) laufen jetzt über eine Sperre nacheinander statt gleichzeitig, und Konflikte werden auch dann erkannt, wenn zwei Speicherungen in derselben Sekunde passieren.
- Duplizieren verbessert: Zeit-Marker werden beim Duplizieren von Songs und ganzen Konzerten jetzt mitkopiert, und eine gerade laufende Speicherung (z. B. ein eben getippter Titel) wird vor dem Duplizieren abgeschlossen statt überschrieben.
- Die Konzert-Detailseite lädt spürbar schneller: Die Noten-Editor-Bibliothek (ein halbes Megabyte) wird erst geladen, wenn der Noten-Editor wirklich geöffnet wird. Zusätzlich werden Textdateien jetzt komprimiert übertragen und Schriften/Assets besser gecacht.
- Bedienung & Barrierefreiheit: Die Popup-Fenster für Notiz, BPM und Noten halten den Tastatur-Fokus jetzt im Fenster und geben ihn beim Schließen an den auslösenden Knopf zurück.
- Sicherheits-Härtung: zusätzliche Herkunfts-Prüfung für alle Schreib-Anfragen, private Konzertdaten werden vom Browser nicht mehr zwischengespeichert, und ein Datenverlust-Kantenfall bei ganz alten, teilmigrierten Konzerten (Track-Zuordnungen im Alt-Format) ist behoben.

## 1.12.2 — 26.06.2026
- Fehler behoben: Wenn man die Datei aus einem bereits belegten Track-Slot entfernt und danach eine andere Datei in denselben Slot zieht, wurde diese nach dem Neuladen nicht gespeichert — der Slot blieb leer, obwohl scheinbar gespeichert wurde. Ursache war ein technischer Sonderfall bei leeren Slots. Das Zuordnen funktioniert jetzt in allen Fällen zuverlässig.

## 1.12.1 — 26.06.2026
- Fehler behoben: Wenn mehrere Personen ein Konzert gleichzeitig geöffnet hatten — oder auch nur ein zweiter Tab nebenher lief — konnte ein gerade zugewiesener Track nach dem Neuladen wieder verschwinden. Ein im Hintergrund laufendes automatisches Speichern (z. B. beim Abspielen oder beim Ermitteln der Track-Längen) überschrieb die Änderung mit einem älteren Stand. Das Programm ist jetzt gegen solches gegenseitige Überschreiben abgesichert: Track-Längen werden getrennt gespeichert und fassen das Programm nicht mehr an, und wenn jemand anderes zwischenzeitlich gespeichert hat, erscheint ein Hinweis zum Neuladen statt eines stillen Datenverlusts.

## 1.12.0 — 18.06.2026
- Neu: BPM pro Song. Neben „Notiz“, „PDF/Bild“ und „Noteneditor“ gibt es jetzt einen Knopf „BPM“. Ein Klick öffnet ein kleines Fenster, in dem du das Tempo (Schläge pro Minute) einträgst. Sobald ein Wert gesetzt ist, steht er direkt im Knopf (z. B. „120 BPM“) und bleibt auch beim reinen Ansehen sichtbar. Feld leeren und speichern entfernt die Angabe wieder; beim Duplizieren wird sie mitkopiert.

## 1.11.6 — 15.06.2026
- Wellenform-Player: Farben umgekehrt — die noch nicht gespielte Spur erscheint jetzt im kräftigen Marken-Blau, der bereits abgespielte Teil gedämpft. So sieht man den Fortschritt klarer.

## 1.11.5 — 15.06.2026
- PDF-Vorschau: Hochgeladene PDFs öffnen sich jetzt wieder direkt in der Vorschau — Chrome hatte die eingebettete Anzeige zuvor blockiert.
- Optik: Die „Notiz“-Anzeige am Song sieht jetzt genauso aus wie die übrigen Anhänge (PDF/Bild, Noten).

## 1.11.4 — 15.06.2026
- Noten-Editor: Das Abspielen funktioniert jetzt — mit einem eingebauten, einfachen Melodie-Player (ohne externe Klänge, läuft auch offline).
- Klarere Benennung: Der Button „+ Noten (PDF/Bild)“ heißt jetzt „PDF/Bild“ — so kommt man nicht mit dem Noteneditor durcheinander.
- Track-Slots: Slots lassen sich in den Einstellungen jetzt zuverlässig anlegen und löschen. Die beiden Grund-Slots (1 und 2) sind geschützt und bleiben erhalten.

## 1.11.3 — 15.06.2026
- Noten-Editor: Die Noten werden jetzt dunkel und gut sichtbar dargestellt (vorher waren sie auf dem weißen Vorschau-Feld fast unsichtbar). Außerdem erscheint kein zusätzlicher Anlegen-Knopf mehr, wenn bereits eine Notiz oder Noten vorhanden sind — das Bearbeiten läuft dann direkt über das vorhandene Element.

## 1.11.2 — 15.06.2026
- Noten-Editor: Das Notenbild wird jetzt in voller Breite und deutlich größer angezeigt und passt sauber in den Vorschau-Bereich — vorher war es viel zu klein und kaum lesbar.

## 1.11.0 — 15.06.2026
- Neu: Noten-Editor. Im Bearbeitungsmodus öffnet der Button „Noteneditor“ (neben „+ Noten“) ein Fenster, in dem du eigene Noten schreiben kannst — mit sofortigem Notenbild und Abspielfunktion. Eine Hilfsleiste und ein Spickzettel machen den Einstieg leicht. Gespeicherte Noten erscheinen — wie hochgeladene Notenblätter — als anklickbares „Noten“-Element am Song und lassen sich jederzeit wieder öffnen und ändern.

## 1.10.0 — 15.06.2026
- Notizen wandern in einen eigenen Notizzettel: Im Bearbeitungsmodus öffnet der neue Button „Notizen“ (neben „+ Noten“) ein Fenster zum Schreiben. Vorhandene Notizen erscheinen — wie hochgeladene Noten — als anklickbares „Notiz“-Element am Song und lassen sich jederzeit per Klick öffnen und ändern, auch außerhalb des Bearbeitungsmodus.

## 1.9.2 — 15.06.2026
- Fehler behoben: Im Einstellungs-Fenster wurde die Checkbox „Freigabe aktiv“ zu breit gezogen und ragte über den Rand hinaus — ein Folgefehler des vorigen Layout-Updates, jetzt korrigiert.

## 1.9.1 — 15.06.2026
- Fehler behoben: Im Einstellungs-Fenster ragten die Probetermine über den Rand hinaus (horizontales Scrollen) — Datum und Notiz teilen sich die Breite jetzt sauber. Außerdem einheitlicheres Aussehen der Auswahl-Menüs (u. a. der neuen Freigabe-Berechtigung).

## 1.9.0 — 15.06.2026
- Neu: Abgestufte Freigaben. Beim Teilen eines Konzerts legst du jetzt fest, was die Betrachter dürfen — „Nur ansehen & abspielen“ (wie bisher), „Marker setzen & bearbeiten“ oder „Das ganze Programm bearbeiten“. So kann die Band auf Wunsch direkt mitarbeiten, ohne einen eigenen Account. Die Freigabe-Einstellungen selbst sowie Löschen, Duplizieren und Backups bleiben immer dem Besitzer vorbehalten.

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
