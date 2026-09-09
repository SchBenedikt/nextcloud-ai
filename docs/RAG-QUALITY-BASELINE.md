# RAG-Qualitäts-Baseline (Issue #146)

EVA misst die Qualität des RAG-Pipelines lokal und deterministisch – ohne
externe Dienste, ohne echte Nutzerdaten. Die Suite
`tests/RagQualityEvaluationTest.php` läuft in jedem PHPUnit-Lauf (also auch in
CI) und schlägt nur bei klaren Regressionen fehl.

## Warum das nötig ist

Leistungsverbesserungen an Indexierung, Chunking oder Retrieval können Antworten
schneller machen und dabei still die Tiefe oder Korrektheit verringern. Die
Baseline macht solche Regressionen messbar, statt sie nur zu vermuten.

## Methodik

- **Fixtures**: synthetische, datenschutzsichere Dokumente (lange Dokumente,
  wiederholte Passagen, Tabellen, Überschriften, mehrsprachiger Text,
  Mail-Verläufe, Fakten am Dateiende). Keine echten Nutzerdaten oder Secrets.
- **Ground truth**: pro Dokument eine `facts`-Map mit erwarteten Fakten samt
  zugehöriger Sektion (strukturelle Provenienz).
- **Deterministischer Retriever**: Statt Embeddings kommt ein lexikalischer
  Stand-in-Retriever zum Einsatz, damit die Zahlen ohne externe Dienste
  reproduzierbar sind. Die Messung ist damit ein Regressionstest für Chunking +
  Retrieval-Ranking; der echte Embedding-Pfad wird separat durch die
  Such- und RAG-Tests abgedeckt.
- **Gemessene Metriken**:
  - Extraktions-/Chunking-Vollständigkeit (Anfang/Mitte/Ende der Datei),
  - Retrieval-Ranking: recall@5 und MRR pro Query, aggregiert pro Sprache,
  - Duplikat-/Diversitätsrate und Kontext-Trunkierungsrate,
  - Zitier-/Provenienz-Gültigkeit: jeder Chunk muss wörtlich in der Quelle
    auffindbar sein, Provenienz-Offsets monoton steigend.

## Aktuelle Baseline (2026-09-09, Chunk-Size 150/Overlap 30)

```
[de] avg recall@5=0.91 avg MRR=0.80 (n=11)
[en] avg recall@5=1.00 avg MRR=0.83 (n=3)
```

Per-Query-Werte (Auszug):

| Fixture | Query | recall@5 | MRR |
| --- | --- | ---: | ---: |
| projektplan (de) | Wie hoch ist das Budget? | 1.00 | 1.00 |
| projektplan (de) | Wann startet das Produkt? | 0.33 | 0.33 |
| tabelle (de) | Wie viele Schrauben M8 sind… | 1.00 | 0.61 |
| tabelle (de) | In welchem Regal liegen… | 0.67 | 0.50 |
| long-tail (de) | Wo steht die Seriennummer? | 1.00 | 1.00 |
| meeting-notes-en (en) | When does the mobile app… | 1.00 | 1.00 |
| meeting-notes-en (en) | What did the documentation… | 1.00 | 0.75 |

## Regression-Schwellen (CI)

- recall@5 ≥ 0.60 und MRR ≥ 0.50 pro Sprache. Werte unterhalb dieser
  konservativen Schwellen brechen den Testlauf.
- Der Baseline-Report wird bei jedem Lauf auf STDERR ausgegeben
  (`[RAG quality baseline - Issue #146]`).

## Ausführen

```bash
# Ganze Suite (läuft automatisch in CI)
composer test

# Nur die Qualitäts-Baseline
vendor/bin/phpunit --filter RagQualityEvaluationTest tests/RagQualityEvaluationTest.php
```

Bei Änderungen an Chunker, Indexierung oder Retrieval die Baseline lokal laufen
lassen und den Report mit dieser Datei abgleichen. Bewusste Verbesserungen der
Schwellenwerte gehören in `tests/RagQualityEvaluationTest.php` **und** hierher.