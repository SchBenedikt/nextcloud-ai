# EVA AI – Funktionen, Arbeitsweise und Grenzen

EVA (`eva_ai`) ist ein selbst gehosteter KI-Assistent für Nextcloud. Die App
verbindet eine Chatoberfläche mit lokalem Ollama, einer durchsuchbaren
Wissensbasis aus Nextcloud-Inhalten und kontrollierten Nextcloud-Aktionen. Dieses
Dokument beschreibt, was EVA kann, auf welchen Oberflächen eine Funktion
verfügbar ist und wo bewusst Sicherheitsgrenzen bestehen.

## 1. Oberflächen

### EVA-Web-App

Die Web-App ist die vollständige Arbeitsoberfläche. Nutzer können mehrere Chats
anlegen, durchsuchen, umbenennen, löschen und als Markdown exportieren. Antworten
werden gestreamt; Lade-, Leer-, Fehler- und Wiederholungszustände werden in der
Oberfläche dargestellt. In der Dokumentansicht lässt sich der persönliche Index
seitenweise durchsuchen. In den Einstellungen werden Ollama, Modelle, Index,
Abrufqualität und Werkzeuge konfiguriert.

Nur in einem interaktiven, authentifizierten Kontext darf EVA Änderungen
vorschlagen. Vor der Ausführung zeigt die Oberfläche den exakten Werkzeugnamen
und dessen Argumente an. Erst **Bestätigen und ausführen** führt die Aktion aus;
**Abbrechen** verändert keine Daten.

### Nextcloud Files

Über **Mit EVA öffnen** kann eine einzelne Datei an EVA übergeben werden. Bei
Mehrfachauswahl öffnet EVA einen gemeinsamen Dateikontext. Die Antworten werden
nur aus den ausgewählten, bereits indexierten Dateien erzeugt und nicht durch
Treffer aus dem restlichen Index ergänzt. Dadurch eignet sich die Funktion etwa
für den Vergleich mehrerer Dokumente oder die Zusammenfassung eines konkreten
Ordnersatzes.

### Nextcloud Assistant / TaskProcessing

EVA registriert Provider für Chat, Zusammenfassung, Überschrift, Themen,
Übersetzung, Umformulierung, Korrektur, Formatierung, Tonänderung und
kontextbezogenes Schreiben. Zusätzlich existieren Provider für Chat mit
freigegebenen Werkzeugen und für mehrstufige Agenteninteraktionen. Die
Provider-Ausführung benötigt einen laufenden Nextcloud-TaskProcessing-Worker.

Vom Aufrufer übermittelte Werkzeugdefinitionen oder Systemanweisungen erweitern
die Berechtigungen nicht. EVA verwendet ihre eigene zentrale Werkzeugrichtlinie.
Schreibende Aktionen werden nur in dem dafür vorgesehenen bestätigten
Assistant-Schritt ausgeführt.

### Nextcloud Talk

Optional kann EVA als Talk-Bot registriert und pro Unterhaltung aktiviert
werden. Der Bot beantwortet Fragen anhand des Index des Nutzers, der ihn
hinzugefügt hat. Talk ist bewusst **nur lesend**: Datei-, Kontakt-, Kalender-,
Freigabe- und Aufgabenänderungen werden dort nicht angeboten. Besonders sensible
Lesewerkzeuge wie Profil-, Freigabelisten- und Serverstatuszugriff sind ebenfalls
gesperrt. Automatisch einbezogener Talk-Verlauf ist mengenmäßig begrenzt.

### Kommandozeile und Hintergrundaufgaben

`occ`-Befehle unterstützen Einrichtung, Indexierung, Zurücksetzen des Index,
Mount-Übersichten, direkte Werkzeugdiagnose und Talk-Bot-Registrierung. Ein
Background Job verarbeitet eingeschriebene Nutzerkonten inkrementell. Große
Bestände werden über mehrere begrenzte Durchläufe fortgesetzt, statt einen
einzigen unbeschränkten Prozess zu starten.

## 2. Lokale Wissenssuche (RAG)

### Indexierbare Inhalte

EVA kann unter anderem Klartext, Markdown, Quellcode, CSV/TSV, HTML, JSON, XML,
YAML, TOML, RTF, SQL, PDF, Microsoft-Office-Dateien, OpenDocument-Dateien und
EPUB verarbeiten. Optional werden E-Mails mit Betreff, Absender und Inhalt in
den Index aufgenommen. Größen-, Pfad- und Mengenlimits verhindern, dass ein
einzelner Lauf unbeschränkt Ressourcen belegt.

Beim ersten authentifizierten Start kann EVA eine klar markierte, editierbare
Profilsektion in `KNOWLEDGE.md` anlegen. Sie enthält nur die dafür vorgesehenen
Basisdaten und überschreibt keine vorhandenen Notizen. Später explizit genannte
persönliche Fakten können über `update_knowledge` ergänzt werden.

### Verarbeitung

1. Der Indexer läuft durch den erlaubten Teil des persönlichen Dateibaums.
2. Unterstützte Inhalte werden extrahiert und in überlappende Abschnitte
   aufgeteilt.
3. Ollama erzeugt Embeddings für diese Abschnitte.
4. EVA speichert Dokumentmetadaten, Textabschnitte und Vektoren in der
   Nextcloud-Datenbank.
5. Gelöschte, geänderte oder nicht mehr lesbare Quellen werden beim Abgleich
   aktualisiert beziehungsweise entfernt.

Bei einer Frage kombiniert EVA semantische Vektorsuche mit lexikalischer
BM25-Suche. Reciprocal Rank Fusion führt beide Ranglisten zusammen. Nur eine
begrenzte Zahl passender Abschnitte gelangt als unprivilegierter Kontext in den
Modellprompt. Die Antwort nennt die verwendeten Quellpfade. Gefundener
Dokumenttext gilt dabei als Datenmaterial und nicht als Systemanweisung; in
Dokumenten versteckte Prompt-Injektionen erhalten dadurch keine zusätzlichen
Berechtigungen.

### Indexsteuerung

Nutzer können den Index manuell starten, stoppen und zurücksetzen sowie
einzubeziehende Pfade, Ausschlüsse, maximale Dateigröße und Dateien pro Lauf
festlegen. Ein Start schreibt das Konto dauerhaft für periodische Durchläufe ein;
diese Einschreibung lässt sich wieder deaktivieren. Ein Stopp bleibt als
`stopping` sichtbar, bis der Worker tatsächlich beendet ist. Bereits vorbereitete
Teilergebnisse werden nach einer Abbruchanforderung nicht veröffentlicht.

Das Löschen des Index entfernt ausschließlich abgeleitete Dokumenteinträge,
Abschnitte und Vektoren. Originaldateien und Chatverläufe bleiben erhalten.

## 3. Verfügbare Werkzeuge

Die folgenden Gruppen beschreiben die Fähigkeiten. Exakte Parameternamen,
Grenzwerte und Rückgabeformen stehen in [`TOOLS.md`](../TOOLS.md).

### Dateien und Wissen

- Dateien und Ordner begrenzt auflisten.
- Lesbare Textdateien öffnen.
- Datei- und Ordnernamen sowie begrenzte Textinhalte durchsuchen; Treffer
  enthalten Fundgrund, Ausschnitt und ein Kennzeichen für abgeschnittene Suchen.
- Textdateien und Markdown-Notizen erstellen, Ordner anlegen und Einträge
  umbenennen.
- Dateien oder leere Ordner löschen, sofern der konfigurierte Löschmodus dies
  erlaubt. Im Standardmodus darf EVA nur über stabile Datei-IDs als selbst
  erstellt markierte Einträge löschen; eine Umbenennung hebt diese Zuordnung
  nicht auf.
- Explizit mitgeteilte persönliche Fakten an `KNOWLEDGE.md` anhängen. Beim
  Erreichen des Limits werden alte normale Notizen entfernt, während die
  automatisch angelegte Identitätssektion erhalten bleibt.

### Kontakte und Profil

- Eigene erreichbare Adressbücher nach Name, E-Mail oder Organisation
  durchsuchen.
- Kontakte mit Name, E-Mail, Telefon und Organisation erstellen, ändern oder
  löschen, wenn das Adressbuch Schreibrechte gewährt.
- Das eigene Nextcloud-Profil lesen und ausgewählte Felder wie Anzeigename,
  E-Mail, Telefon, Website, Adresse, Organisation, Rolle, Überschrift,
  Biografie und Pronomen ändern.

### Kalender und Aufgaben

- Kalender auflisten und Termine innerhalb eines begrenzten Zeitfensters
  abrufen. Wiederholungen mit RRULE, RDATE, EXDATE und RECURRENCE-ID werden
  begrenzt expandiert; zeitgesteuerte Termine werden korrekt als UTC ausgegeben.
- Termine mit Ort, Beschreibung, Kategorien, Dauer und Erinnerung erstellen,
  ändern oder löschen. Lokale Zeitangaben werden in der Zeitzone des Nutzers
  interpretiert; reine Datumswerte erzeugen ganztägige Termine.
- Freie Arbeitszeitfenster suchen. EVA fragt den CalDAV-Server bereits mit dem
  relevanten Zeitraum ab und lädt nicht den gesamten Kalenderbestand.
- VTODO-Aufgaben auflisten, erstellen, ändern, abschließen und löschen;
  unterstützt werden Fälligkeit, Beschreibung, Kategorien, Status und Priorität.
- Schreibgeschützte Kalender werden vor Änderungen abgewiesen.

### E-Mail

Wenn die Nextcloud-Mail-App und deren API verfügbar sind, kann EVA aktuelle oder
ungelesene Nachrichten auflisten, nach Betreff/Absender/Vorschau suchen, einzelne
Nachrichten lesen und die Zahl ungelesener Nachrichten ausgeben. Suchmuster und
Ergebnismengen sind begrenzt. Der Mail-Index ist separat abschaltbar.

### Freigaben

- Ausgehende und eingehende Freigaben auflisten.
- Öffentliche Links sowie Nutzer- und Gruppenfreigaben mit optionalem Passwort,
  Ablaufdatum, Schreibrecht und Notiz erzeugen.
- Eigene Freigaben über ihre stabile Provider-ID aktualisieren oder löschen.
- Bereits bestehende öffentliche Tokens und URLs werden aus Listenresultaten
  entfernt, damit sie weder im Modellkontext noch im Chatverlauf landen. Nur ein
  gerade neu erstellter Link wird einmalig als Ergebnis zurückgegeben.

### Aktivität, Zeit, Wetter und Status

EVA kann den persönlichen Nextcloud-Aktivitätsstrom lesen, Datum und Uhrzeit in
der Nutzerzeitzone bestimmen, technische App-/Ollama-Informationen liefern und
über Open-Meteo eine dreitägige Wettervorhersage abrufen. Vor Berechnungen mit
relativen Datumsangaben soll das Modell immer das Zeitwerkzeug verwenden.

Der Wetterabruf ist eine optionale externe Netzwerkanfrage. Er ist vom lokalen
RAG- und Ollama-Datenfluss getrennt; wer keinerlei externe Anfrage wünscht,
sollte das Wetterwerkzeug nicht verwenden beziehungsweise über die Richtlinie
deaktivieren.

## 4. Sicherheitsmodell

Alle Werkzeuge laufen im Kontext des angemeldeten Nextcloud-Nutzers. EVA erhält
dadurch keine globalen Dateirechte und darf keine fremden Benutzerbestände
durchsuchen. `ToolPolicy` ist die zentrale Positivliste und unterscheidet zwischen
lesenden, schreibenden und zerstörerischen Aktionen sowie zwischen Web, Talk,
RAG und TaskProcessing.

Wesentliche Schutzmaßnahmen:

- Schreibende und zerstörerische Aktionen benötigen auf unterstützten
  interaktiven Oberflächen eine explizite Bestätigung.
- Talk sowie die normale RAG-/TaskProcessing-Vorschlagsphase bleiben lesend.
- Pfade werden relativ zum persönlichen Home-Verzeichnis normalisiert;
  Traversal außerhalb dieses Bereichs wird abgewiesen.
- Schreibinhalte, Suchläufe, Ergebniszahlen, Kontextabschnitte und
  Agentenrunden besitzen feste Obergrenzen.
- EVA prüft den aktuellen Lesezugriff erneut, bevor ein gecachter RAG-Treffer
  verwendet wird. Nicht mehr zugängliche Einträge werden verworfen.
- Ollama-URLs akzeptieren nur die vorgesehenen HTTP(S)-Bestandteile und keine
  eingebetteten Zugangsdaten, Queryparameter oder Fragmente.
- Öffentliche Share-Tokens werden nicht als bestehende Daten an das Modell
  weitergegeben.
- Verwaiste Agentenzustände werden zeitgesteuert bereinigt.

Weitere Details und das Bedrohungsmodell stehen in
[`SECURITY.md`](SECURITY.md), Datenschutzangaben in [`PRIVACY.md`](PRIVACY.md).

## 5. Datenschutz und Datenorte

Dateien verbleiben in Nextcloud. Indexdaten liegen in den EVA-Datenbanktabellen,
Chatverläufe in einem pro Nutzer gehashten AppData-Namensraum und
Aktionsmarkierungen in AppData. Modellanfragen gehen an die konfigurierte
Ollama-URL. Bei einem Ollama-Dienst auf einem anderen Rechner verlassen Prompt
und Kontext entsprechend den Nextcloud-Host; Betreiber müssen diese Verbindung
selbst absichern.

Die Kernfunktionen benötigen keinen kommerziellen KI-API-Schlüssel. Optionale
externe Funktionen – derzeit insbesondere Wetter über Open-Meteo – sind nicht
mit der lokalen Wissenssuche gleichzusetzen und sollten anhand der eigenen
Datenschutzanforderungen aktiviert werden.

## 6. Bewusste Grenzen

- EVA ist kein Berechtigungssystem: Nextcloud bleibt die maßgebliche Instanz für
  Identität, Freigaben und DAV-Rechte.
- Nicht unterstützte, verschlüsselte oder zu große Dateien werden übersprungen.
- Die Qualität einer Antwort hängt von Extraktion, Indexstand, Treffern und dem
  ausgewählten Ollama-Modell ab. Quellenangaben ersetzen keine fachliche Prüfung.
- Datei-Kontext-Chats benötigen bereits indexierte Dateien.
- Mail, Talk, Notifications und Assistant funktionieren nur, wenn die jeweiligen
  Nextcloud-Apps installiert, aktiviert und korrekt konfiguriert sind.
- TaskProcessing-Aufgaben benötigen einen Worker; ohne ihn bleiben sie geplant.
- Live-Websuche ist derzeit nicht implementiert. Das Wetterwerkzeug ist keine
  allgemeine Websuche.
- Bestätigte Aktionen sind gegen versehentliche Ausführung geschützt, aber nicht
  als verteilte Exactly-once-Transaktion garantiert. Gleichzeitige Anfragen oder
  Infrastrukturfehler können weiterhin eine erneute Zustandsprüfung erfordern.

## 7. Typische Einsatzbeispiele

- „Fasse die drei ausgewählten Projektberichte zusammen und nenne Unterschiede.“
- „Welche meiner Notizen erwähnen das Budget 2027?“
- „Zeige meine Termine der nächsten sieben Tage und finde am Mittwoch einen
  freien 45-Minuten-Slot.“
- „Entwirf ein Sitzungsprotokoll als Markdown-Datei.“ – EVA zeigt den geplanten
  Dateipfad und Inhalt vor der Ausführung zur Bestätigung.
- „Finde den Kontakt von Erika und erstelle danach einen Termin.“ – Lesen kann
  direkt erfolgen; jede Änderung wird separat bestätigt.
- „Welche ungelesenen Mails betreffen die Rechnung?“ – verfügbar bei aktivierter
  Mail-Integration.
- „Erstelle einen bis Freitag gültigen Download-Link für den Bericht.“ – der neue
  Link wird nach Bestätigung einmalig ausgegeben; spätere Listen maskieren ihn.

## 8. Weiterführende Dokumentation

- [`TOOLS.md`](../TOOLS.md) – vollständige Werkzeugparameter und Rückgaben
- [`CONFIGURATION.md`](CONFIGURATION.md) – alle Einstellungen und Standardwerte
- [`ARCHITECTURE.md`](ARCHITECTURE.md) – Komponenten, Datenfluss und Tabellen
- [`SECURITY.md`](SECURITY.md) – Sicherheitsgrenzen und Betriebsmaßnahmen
- [`PRIVACY.md`](PRIVACY.md) – verarbeitete Daten und Datenschutz
- [`DEVELOPMENT.md`](DEVELOPMENT.md) – Entwicklung, Build und Tests
- [`FAQ.md`](FAQ.md) – häufige Fragen und Fehlerbehebung
