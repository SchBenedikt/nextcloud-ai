# AI (ragchat) — Lokaler RAG-Assistent für Nextcloud

Chatten Sie mit einem lokal laufenden Large Language Model über Ihre eigenen Nextcloud-Dateien. Die App indexiert Dateien, zerlegt sie in Textbausteine, erstellt Embeddings daraus und macht sie per RAG durchsuchbar. Antworten nennen immer die Quelle (Dateipfad).

- Reines PHP, kein externer RAG-Dienst, keine externe Datenbank
- Unterstützte Formate: Text (txt, md, code, csv, tsv, html, json, xml, yaml, toml, rtf, sql, ...), PDF, Office (docx/xlsx/pptx inkl. Makro-/Template-Varianten), OpenDocument (odt, ods, odp), EPUB und mehr
- Hybride Suche: Vektorsuche + lexikalische Suche (BM25) mit RRF-Fusion
- Werkzeuge im Chat: Dateien/Vorlagen anlegen, umbenennen, durchsuchen; Kontakte lesen/ändern (System-Tools); Kalender-Termine anlegen/ändern/löschen (deutsche Zeitformate, Erinnerungen); Wetter (Open-Meteo); aktuelle Uhrzeit in Nutzer-Zeitzone
- TaskProcessing-Provider (IDs `ragchat:text2text`), kompatibel zur Assistant-App
- Benachrichtigung „AI answer ready“ über die Notifications-App
- Konfigurationsfrei: Embedding- und Chat-Modell über die lokale Ollama-Instanz

## Voraussetzungen

- Nextcloud 30–34 (getestet mit 34)
- PHP-Extension `curl` (üblich bei Nextcloud-Installationen)
- Ollama, erreichbar vom Webserver. Standard-URL `http://127.0.0.1:11434` (undAPI-Key nicht nötig bei klassischem Setup), mit den Modellen:
  - `gemma4:cloud` (Chat, Standard)
  - `nomic-embed-text:latest` (Embedding)

## Installation

### 1. App einspielen

Der Ordner muss `ragchat` heißen (App-ID):

```bash
cd /var/www/nextcloud/apps
sudo git clone https://github.com/SchBenedikt/nextcloud-ai.git ragchat
sudo chown -R www-data:www-data ragchat
```

### 2. App aktivieren

```bash
cd /var/www/nextcloud
sudo -u www-data php occ app:enable ragchat
```

### 3. Ollama vorbereiten

```bash
ollama pull gemma4:cloud
ollama pull nomic-embed-text:latest
```

Läuft Ollama auf einem anderen Rechner / anderem Port, z. B.:

```bash
sudo -u www-data php occ config:app:set ragchat ollama_url --value=http://192.168.1.50:11434
```

Weitere Einstellungen (Default-Werte in Klammern) — Optionalerhalter:

```bash
sudo -u www-data php occ config:app:set ragchat chat_model       --value=gemma4:cloud      # Chat-Modell (gemma4:cloud)
sudo -u www-data php occ config:app:set ragchat embedding_model  --value=nomic-embed-text  # Embedding (nomic-embed-text)
sudo -u www-data php occ config:app:set ragchat temperature      --value=0.1               # Kreativität (0.1)
sudo -u www-data php occ config:app:set ragchat context_size     --value=12288             # Kontext-Länge (12288)
sudo -u www-data php occ config:app:set ragchat top_k            --value=6                 # Treffer im RAG (6)
sudo -u www-data php occ config:app:set ragchat chunk_size       --value=900               # Chunk-Größe (900)
sudo -u www-data php occ config:app:set ragchat chunk_overlap    --value=120               # Überlappung (120)
sudo -u www-data php occ config:app:set ragchat index_enabled    --value=1                 # 0 = Index deaktiviert
sudo -u www-data php occ config:app:set ragchat actions_enabled  --value=1                 # 0 = keine Werkzeuge im Chat
```

### 4. TaskProcessing / Assistant (optional, empfohlen)

Soll die App über das Assistant-Feld (AI-App im Menü) ansprechbar sein, Tasktyp aktivieren. Der Worker-Cron verarbeitet die Tasks — ohne Worker bleiben sie „scheduled“ (HTTP 417 im UI-Polling):

```bash
sudo -u www-data php occ taskprocessing:task-type:set-enabled core:text2text:chat 1
```

Datei `/etc/cron.d/ragchat-taskprocessing`:

```
* * * * * www-data /usr/bin/php -d error_reporting=0 /var/www/nextcloud/occ taskprocessing:worker -t 60 -i 2 >/dev/null 2>&1
```

### 5. Benachrichtigungen (optional)

Glocken-Benachrichtigung „AI answer ready“ nach abgeschlossenen Antworten:

```bash
sudo -u www-data php occ app:enable notifications
sudo -u www-data php occ config:app:set ragchat notify_on_complete --value=1
```

### 6. Erster Start & Index

1. Seite `/apps/ragchat` als Benutzer öffnen.
2. In den App-Einstellungen den Index über den Index-Button starten (Nutzer, dessen Home-Verzeichnis indexiert wird, via `index_user` — leer = aktueller Nutzer).
3. Danach chatten. Werkzeuge (Dateien, Kontakte, Kalender, Wetter, Uhrzeit) sind standardmäßig aktiv; Quelle jeder Antwort ist links mit Dateipfadangabe.

## Kalender-Tools: unterstützte Zeitformate

Der Chat interpretiert Zeitangaben in der Zeitzone des Benutzers (z. B. Europe/Berlin):

- ISO mit/ohne Z: `2026-08-20T16:00:00Z`, `2026-08-20 16:00` (Z wird für Nicht-UTC-Zeitzonen als lokale Zeit gelesen)
- Deutsch: `20.08.2026 16:00`, `20/08/2026 16:00`
- Relativ: `morgen 09:00`, `in 2 Tagen um 10:30`
- Nur ein Datum → Ganztages-Termin
- Erinnerungen über den Parameter `reminder_minutes` (z. B. 30, 60)

## Hinweise

- Der Index (Chunks + Embeddings) liegt im Nextcloud-App-Data-Verzeichnis, nicht in einer externen Datenbank.
- Bekannte Abhängigkeit: Ollama muss die Models lokal geladen haben (`ollama pull`), sonst ist die erste Antwort langsam.