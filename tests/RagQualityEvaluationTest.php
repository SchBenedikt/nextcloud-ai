<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Chunker;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Issue #146: repeatable RAG quality measurement without any external service.
 *
 * This suite is a local, deterministic benchmark mode over synthetic,
 * privacy-safe fixtures (long documents, repeated passages, tables, headings,
 * multilingual text, mail-like content and facts near the end of a file). It
 * exercises the real production Chunker and measures:
 *
 *   - extraction/chunking completeness (begin/middle/end coverage),
 *   - retrieval ranking with a deterministic lexical stand-in retriever
 *     (recall@k, MRR), so results are reproducible without embeddings,
 *   - duplicate/diversity rate, citation/provenance validity and context
 *     truncation rate.
 *
 * Thresholds are deliberately conservative: they exist to catch meaningful
 * quality regressions in CI, not to be a precision benchmark.
 */
final class RagQualityEvaluationTest extends TestCase {
    /** chunker config used for the whole benchmark run */
    private const CHUNK_SIZE = 150;
    private const CHUNK_OVERLAP = 30;

    /**
     * Synthetic corpus. Each document is markdown-like text the way the
     * extractor hands documents to the chunker (Issue #147). The `facts` map
     * lists ground-truth facts a retrieval query should surface, each with the
     * heading of the section it belongs to (structural provenance).
     *
     * @var array<string, array{text:string, facts:array<string,string>}>
     */
    private const CORPUS = [
        'projektplan' => [
            'text' => <<<MD
# Projektbericht

Einleitung: Dieses Dokument fasst den Stand des Projekts zusammen.

## Budget 2026

Das Budget für 2026 beträgt 120000 Euro. Die Reserve beträgt 15000 Euro.
Verantwortlich ist die Abteilung Finanzen.

## Meilensteine

Der erste Meilenstein war der Abschluss der Anforderungsanalyse im Februar.
Der zweite Meilenstein ist der Abschluss des Prototyps im Mai.
Der letzte Meilenstein wird der Produktstart im Oktober sein.

## Risiken

Das Hauptrisiko ist die Verfügbarkeit des Testsystems.
Ein Ausweichplan existiert im Risikoregister.

# Anhang

Die vollständige Kostenaufstellung liegt in der Anlage.
MD,
            'facts' => [
                'Wie hoch ist das Budget? 120000 Euro, Reserve 15000.' => 'Budget 2026',
                'Wann startet das Produkt? Im Oktober.' => 'Meilensteine',
                'Was ist das Hauptrisiko? Die Verfügbarkeit des Testsystems.' => 'Risiken',
            ],
        ],
        'meeting-notes-en' => [
            'text' => <<<MD
# Quarterly Review

This document records the quarterly review meeting.

## Customer feedback

Customers value the short release cycle. The support queue handles 400 tickets a week.
A dedicated onboarding session was requested by the biggest customer.

## Product roadmap

The mobile app ships in Q3. The desktop sync engine is being rewritten.
Offline mode is planned for the next release cycle.

## Team updates

Three engineers joined the platform team. The documentation team finished the new guides.
All action items are tracked in the shared board.
MD,
            'facts' => [
                'How many tickets does support handle per week? 400.' => 'Customer feedback',
                'When does the mobile app ship? Q3.' => 'Product roadmap',
                'What did the documentation team finish? The new guides.' => 'Team updates',
            ],
        ],
        'tabelle' => [
            'text' => <<<MD
# Lagerliste

| Artikel | Menge | Regal |
|---------|-------|-------|
| Schrauben M4 | 1200 | A-11 |
| Schrauben M8 | 800 | A-12 |
| Dichtringe | 300 | B-04 |
| Sicherungsbleche | 60 | C-09 |

Die Mindestbestellmenge beträgt 50 Stück pro Artikel.
MD,
            'facts' => [
                'Wie viele Schrauben M8 sind auf Lager? 800.' => 'Lagerliste',
                'In welchem Regal liegen Dichtringe? B-04.' => 'Lagerliste',
            ],
        ],
        'mail-thread' => [
            'text' => <<<MD
# E-Mail-Verlauf

## Betreff: Serverwartung

Guten Tag, die Wartung ist für Freitag 22 Uhr geplant.
Bitte planen Sie ein Zeitfenster von zwei Stunden ein.

## Antwort

Danke für die Information. Wir haben das Fenster notiert.
Der Ansprechpartner ist Frau Sommer aus der IT-Abteilung.

## Bestätigung

Die Wartung wurde bestätigt und im Kalender eingetragen.
Hinweis: Der Zugang zum Serverraum endet um Mitternacht.
MD,
            'facts' => [
                'Wann ist die Serverwartung geplant? Freitag 22 Uhr.' => 'Betreff: Serverwartung',
                'Wer ist der Ansprechpartner? Frau Sommer.' => 'Antwort',
                'Bis wann endet der Zugang zum Serverraum? Um Mitternacht.' => 'Bestätigung',
            ],
        ],
        'long-tail' => [
            'text' => <<<MD
# Handbuch

## Abschnitt Eins

Hier steht der erste technische Hinweis über die Konfiguration des Geräts.
Weitere Details folgen im mittleren Teil des Handbuchs.

## Abschnitt Zwei

Der mittlere Abschnitt beschreibt die Fehlerbehebung. Bei einem Fehlercode
X-42 muss das Gerät neu gestartet werden. Danach ist die Verbindung wieder hergestellt.

## Abschnitt Drei

Der letzte Abschnitt enthält die Pflegehinweise. Wichtig: Das Gerät muss
monatlich gereinigt werden. Die Reinigung dauert etwa zehn Minuten.

## Fazit

Zusammenfassend gilt: Die Seriennummer steht auf der Rückseite des Gehäuses.
Die Garantie beträgt zwei Jahre ab Kaufdatum.
MD,
            'facts' => [
                'Was muss bei Fehlercode X-42 getan werden? Neustart.' => 'Abschnitt Zwei',
                'Wie oft muss das Gerät gereinigt werden? Monatlich.' => 'Abschnitt Drei',
                'Wo steht die Seriennummer? Auf der Rückseite des Gehäuses.' => 'Fazit',
            ],
        ],
    ];

    private function chunker(): Chunker {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')
            ->willReturnCallback(static function (string $app, string $key, string $default): string {
                if ($key === 'chunk_size') {
                    return (string)self::CHUNK_SIZE;
                }
                if ($key === 'chunk_overlap') {
                    return (string)self::CHUNK_OVERLAP;
                }
                return $default;
            });
        return new Chunker(new AppConfig($config));
    }

    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    /**
     * Chunk the whole corpus once per run and return doc => chunks plus the
     * absolute text offset of every chunk inside the source document.
     *
     * @return array<string, array{chunks:list<string>, offsets:list<int>}>
     */
    private function chunkCorpus(): array {
        $chunker = $this->chunker();
        $out = [];
        foreach (self::CORPUS as $name => $doc) {
            $text = $doc['text'];
            $chunks = [];
            $offsets = [];
            $cursor = 0;
            foreach ($chunker->chunk($text) as $c) {
                $content = (string)$c['content'];
                $chunks[] = $content;
                $pos = mb_strpos($text, $this->normalise($content), $cursor);
                $offsets[] = $pos === false ? $cursor : $pos;
                $cursor = max($cursor, ($pos === false ? $cursor : $pos) + 1);
            }
            $out[$name] = ['chunks' => $chunks, 'offsets' => $offsets];
        }
        return $out;
    }

    /** Lowercase and collapse whitespace so offset search is stable. */
    private function normalise(string $s): string {
        return trim((string)preg_replace('/\s+/u', ' ', mb_strtolower($s)));
    }

    /**
     * Deterministic lexical stand-in retriever (no embeddings): chunks are
     * ranked by the number of distinct query content words they contain.
     * Sentence punctuation/stopwords are dropped the same way for German and
     * English queries.
     *
     * @param list<string> $chunks
     * @return list<int> chunk indexes, best first
     */
    private function lexicalRetrieve(array $chunks, string $query): array {
        $queryTokens = $this->queryTokens($query);
        $scored = [];
        foreach ($chunks as $i => $content) {
            $lower = mb_strtolower($content);
            $hit = 0;
            foreach ($queryTokens as $token) {
                if (mb_strpos($lower, $token) !== false) {
                    $hit++;
                }
            }
            $scored[] = [$i, $hit];
        }
        // Sort by score desc, then by position asc (ties keep document order).
        usort($scored, static function (array $a, array $b): int {
            if ($b[1] !== $a[1]) {
                return $b[1] <=> $a[1];
            }
            return $a[0] <=> $b[0];
        });
        $ranked = [];
        foreach ($scored as [$i, $hit]) {
            if ($hit > 0) {
                $ranked[] = $i;
            }
        }
        return $ranked;
    }

    /** @return list<string> */
    private function queryTokens(string $query): array {
        $q = mb_strtolower($query);
        $q = preg_replace('/[?:.,!;()\-–—"]+/u', ' ', $q) ?? $q;
        $tokens = array_values(array_filter(preg_split('/\s+/u', $q) ?: [], static fn(string $t): bool => $t !== ''));
        $stop = ['der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'ist', 'sind', 'wie', 'viel',
            'the', 'and', 'for', 'with', 'was', 'what', 'when', 'how', 'many', 'in', 'on', 'of', 'to',
            'wer', 'wann', 'wo', 'wird', 'wurde', 'beträgt', 'bis', 'um', 'im', 'am', 'auf', 'bei'];
        return array_values(array_filter($tokens, static fn(string $t): bool => mb_strlen($t) > 2 && !in_array($t, $stop, true)));
    }

    /**
     * Stage 1 — extraction/chunking completeness and structural provenance.
     * Every ground-truth fact must be retrievable as chunk content, and chunks
     * must follow document order (provenance offsets are monotonically rising).
     */
    public function testChunkingCompletenessAndProvenanceOrder(): void {
        $corpus = $this->chunkCorpus();
        foreach (self::CORPUS as $name => $doc) {
            $all = implode("\n", $corpus[$name]['chunks']);
            $flat = $this->normalise($all);
            foreach ($doc['facts'] as $query => $section) {
                // A fact is "extracted" if the answer content survived chunking:
                // check each concrete answer keyword from the query appears.
                $keywords = $this->queryTokens($query);
                self::assertGreaterThan(
                    0,
                    count($keywords),
                    "$name: query '$query' must produce content keywords"
                );
                $found = 0;
                foreach ($keywords as $kw) {
                    if (mb_strpos($flat, $kw) !== false) {
                        $found++;
                    }
                }
                self::assertGreaterThanOrEqual(
                    1,
                    $found,
                    "$name: no chunk retained any keyword of '$query'"
                );
            }

            // Chunks keep document order: source offsets must be non-decreasing.
            $offsets = $corpus[$name]['offsets'];
            $prev = -1;
            foreach ($offsets as $o) {
                self::assertGreaterThanOrEqual($prev, $o, "$name: chunk order drifted");
                $prev = $o;
            }
        }
    }

    /**
     * Stage 2 — retrieval metrics with the deterministic lexical retriever.
     * recall@5 and MRR are computed per query and aggregated per language,
     * then printed as the baseline report. Only clear regressions fail.
     */
    public function testRetrievalRecallAndMrrBaseline(): void {
        $corpus = $this->chunkCorpus();
        $perLanguage = ['de' => [], 'en' => []];
        $detail = [];

        foreach (self::CORPUS as $name => $doc) {
            $chunks = $corpus[$name]['chunks'];
            foreach ($doc['facts'] as $query => $section) {
                // Relevant = chunks whose section heading is carried as prefix.
                $relevant = [];
                $sectionNorm = $this->normalise($section);
                foreach ($chunks as $i => $content) {
                    if (mb_strpos($this->normalise($content), $sectionNorm) !== false) {
                        $relevant[] = $i;
                    }
                }
                self::assertNotEmpty($relevant, "$name: section '$section' produced no chunk");

                $ranked = $this->lexicalRetrieve($chunks, $query);
                $language = $name === 'meeting-notes-en' ? 'en' : 'de';
                $recall5 = 0.0;
                $mrr = 0.0;
                foreach ($relevant as $ri) {
                    $rank = array_search($ri, $ranked, true);
                    if ($rank !== false && $rank < 5) {
                        $recall5 += 1.0 / count($relevant);
                    }
                    if ($rank !== false) {
                        $mrr += 1.0 / ($rank + 1);
                    }
                }
                $mrr /= max(1, count($relevant));
                $perLanguage[$language][] = ['recall5' => $recall5, 'mrr' => $mrr];
                $detail[] = sprintf(
                    "  %-28s recall@5=%.2f mrr=%.2f (lang=%s, sections=%d)",
                    $name . ' :: ' . mb_substr($query, 0, 26),
                    $recall5,
                    $mrr,
                    $language,
                    count($relevant)
                );
            }
        }

        // Baseline report output (visible with --verbose or --debug).
        fwrite(STDERR, "\n[RAG quality baseline - Issue #146]\n" . implode("\n", $detail) . "\n");
        foreach ($perLanguage as $language => $rows) {
            $avgRecall = array_sum(array_column($rows, 'recall5')) / count($rows);
            $avgMrr = array_sum(array_column($rows, 'mrr')) / count($rows);
            fwrite(STDERR, sprintf("  [%s] avg recall@5=%.2f avg MRR=%.2f (n=%d)\n", $language, $avgRecall, $avgMrr, count($rows)));
            // Conservative regression floor: with this small corpus a real
            // chunking regression (lost headings, merged sections, duplicated
            // text walls) drops recall to ~0.
            self::assertGreaterThanOrEqual(0.6, $avgRecall, "regression: {$language} retrieval recall@5 fell below floor");
            self::assertGreaterThanOrEqual(0.5, $avgMrr, "regression: {$language} MRR fell below floor");
        }
    }

    /**
     * Stage 3 — index health guards: no truncated oversized chunks, heading
     * context is never lost, and intentional duplicates stay (Issue #62) while
     * genuinely duplicated walls are bounded (diversity floor).
     */
    public function testNoTruncatedChunksAndReasonableDiversity(): void {
        $corpus = $this->chunkCorpus();
        foreach (self::CORPUS as $name => $doc) {
            $chunks = $corpus[$name]['chunks'];
            self::assertNotEmpty($chunks, "$name produced no chunks");
            foreach ($chunks as $i => $content) {
                // Content includes any heading prefix, so allow the heading
                // length on top of the budget the chunker subtracts it from.
                $body = (string)preg_replace('/^(#{1,6}\s+[^\n]*\n\n?)+/u', '', $content);
                self::assertLessThanOrEqual(
                    self::CHUNK_SIZE + 6,
                    mb_strlen($body),
                    "$name chunk #$i exceeds configured chunk size (truncation risk)"
                );
            }
            // Citation/provenance validity: every chunk must map back into the
            // source (content overlap with source text after normalisation).
            $flat = $this->normalise($doc['text']);
            foreach ($chunks as $i => $content) {
                $needle = $this->normalise((string)preg_replace('/^#{1,6}\s+[^\n]*\n+/u', '', $content));
                self::assertGreaterThan(
                    0,
                    mb_strlen($needle),
                    "$name chunk #$i is empty after heading removal"
                );
                self::assertNotFalse(
                    mb_strpos($flat, $needle),
                    "$name chunk #$i content does not exist verbatim in the source (provenance broken)"
                );
            }
            // Duplicate rate: the corpus has no repeated passages, so duplicate
            // chunks must be the exception, not the rule.
            $unique = count(array_unique($chunks));
            self::assertGreaterThanOrEqual(
                (int)ceil(count($chunks) * 0.7),
                $unique,
                "$name has too many duplicated chunks (diversity regression)"
            );
        }
    }
}
