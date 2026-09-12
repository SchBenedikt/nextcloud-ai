<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\Indexer;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ZipArchive;

/**
 * Regression tests for the extraction layer (Issues #60/#66): supported
 * ZIP-based office formats must yield complete, structured text - headers,
 * footers, footnotes, sheet names, inline strings, speaker notes - and must
 * never silently return empty for a well-formed container.
 */
final class IndexerExtractionTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    private function extractor(): Indexer {
        $reflection = new ReflectionClass(Indexer::class);
        /** @var Indexer $indexer */
        $indexer = $reflection->newInstanceWithoutConstructor();
        return $indexer;
    }

    private function invokeExtract(Indexer $indexer, File $file): string {
        $reflection = new ReflectionClass(Indexer::class);
        $method = $reflection->getMethod('extractText');
        return (string)$method->invoke($indexer, $file);
    }

    private function mockFile(string $name, string $mime, string $content): File {
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn($name);
        $file->method('getMimeType')->willReturn($mime);
        $file->method('getContent')->willReturn($content);
        $file->method('getPath')->willReturn('/user/files/' . $name);
        return $file;
    }

    /** Build a zip archive in memory from a map of entry path => raw content. */
    private function zipBytes(array $entries): string {
        $tmp = tempnam(sys_get_temp_dir(), 'fxt_');
        $this->assertNotFalse($tmp);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp, ZipArchive::OVERWRITE));
        foreach ($entries as $path => $content) {
            $this->assertTrue($zip->addFromString($path, $content));
        }
        $this->assertTrue($zip->close());
        $bytes = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    public function testDocxIncludesBodyHeadersFootersAndFootnotes(): void {
        $docx = $this->zipBytes([
            'word/document.xml' => '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                . '<w:body><w:p><w:r><w:t>Body opening paragraph</w:t></w:r></w:p>'
                . '<w:p><w:r><w:t xml:space="preserve">Final body sentence near the end of the file</w:t></w:r></w:p>'
                . '</w:body></w:document>',
            'word/header1.xml' => '<w:hdr><w:p><w:r><w:t>Confidential Draft v2</w:t></w:r></w:p></w:hdr>',
            'word/footer1.xml' => '<w:ftr><w:p><w:r><w:t>Page footer with contact</w:t></w:r></w:p></w:ftr>',
            'word/footnotes.xml' => '<w:footnotes><w:footnote w:id="1"><w:p><w:r><w:t>Footnote source reference</w:t></w:r></w:p></w:footnote></w:footnotes>',
            'word/comments.xml' => '<w:comments><w:comment w:id="0"><w:p><w:r><w:t>Reviewer comment text</w:t></w:r></w:p></w:comment></w:comments>',
        ]);
        $file = $this->mockFile(
            'report.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $docx
        );
        $text = $this->invokeExtract($this->extractor(), $file);

        self::assertStringContainsString('Body opening paragraph', $text);
        self::assertStringContainsString('Final body sentence near the end of the file', $text);
        self::assertStringContainsString('[Header]', $text);
        self::assertStringContainsString('Confidential Draft v2', $text);
        self::assertStringContainsString('[Footer]', $text);
        self::assertStringContainsString('Page footer with contact', $text);
        self::assertStringContainsString('[Footnotes]', $text);
        self::assertStringContainsString('Footnote source reference', $text);
        self::assertStringContainsString('[Comments]', $text);
        self::assertStringContainsString('Reviewer comment text', $text);
    }

    public function testXlsxIncludesSharedAndInlineStringsWithSheetNames(): void {
        $xlsx = $this->zipBytes([
            'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="Umsatz" sheetId="1" r:id="rId1"/>'
                . '<sheet name="Notes" sheetId="2" r:id="rId2"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
                . '</Relationships>',
            'xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<si><t>Shared cell value Alpha</t></si></sst>',
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<sheetData><row r="1">'
                . '<c r="A1" t="s"><v>0</v></c>'
                . '<c r="B1" t="inlineStr"><is><t>Inline value Beta</t></is></c>'
                . '<c r="C1"><v>42</v></c>'
                . '</row></sheetData></worksheet>',
            'xl/worksheets/sheet2.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Second sheet content</t></is></c></row></sheetData></worksheet>',
        ]);
        $file = $this->mockFile(
            'umsatz.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $xlsx
        );
        $text = $this->invokeExtract($this->extractor(), $file);

        self::assertStringContainsString('[Sheet: Umsatz]', $text);
        self::assertStringContainsString('A1: Shared cell value Alpha', $text);
        self::assertStringContainsString('B1: Inline value Beta', $text);
        self::assertStringContainsString('C1: 42', $text);
        self::assertStringContainsString('[Sheet: Notes]', $text);
        self::assertStringContainsString('Second sheet content', $text);
    }

    public function testPptxIncludesSpeakerNotesAndAllSlides(): void {
        $pptx = $this->zipBytes([
            'ppt/slides/slide1.xml' => '<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" '
                . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
                . '<p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>First slide headline</a:t></a:r></a:p>'
                . '</p:txBody></p:sp></p:spTree></p:cSld></p:sld>',
            'ppt/slides/slide2.xml' => '<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" '
                . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
                . '<p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>Second slide content</a:t></a:r></a:p>'
                . '</p:txBody></p:sp></p:spTree></p:cSld></p:sld>',
            'ppt/notesSlides/notesSlide1.xml' => '<p:notes xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" '
                . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
                . '<p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>Speaker note about slide one</a:t></a:r></a:p>'
                . '</p:txBody></p:sp></p:spTree></p:cSld></p:notes>',
        ]);
        $file = $this->mockFile(
            'vortrag.pptx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            $pptx
        );
        $text = $this->invokeExtract($this->extractor(), $file);

        self::assertStringContainsString('[Slide 1]', $text);
        self::assertStringContainsString('First slide headline', $text);
        self::assertStringContainsString('[Slide 2]', $text);
        self::assertStringContainsString('Second slide content', $text);
        self::assertStringContainsString('[Speaker notes (slide 1)]', $text);
        self::assertStringContainsString('Speaker note about slide one', $text);
    }

    public function testHtmlKeepsTitleAndHeadingsAsStructuralAnchors(): void {
        $file = $this->mockFile(
            'bericht.html',
            'text/html',
            '<html><head><title>Quartalsbericht 2026</title></head><body>'
            . '<h1>Einfuehrung</h1><p>Eroeffnender Absatz ueber die Lage.</p>'
            . '<h2>Kennzahlen</h2><p>Der Umsatz stieg um zwoelf Prozent.</p>'
            . '<script>var schmutz = 1;</script><style>.x{color:red}</style>'
            . '</body></html>'
        );
        $text = $this->invokeExtract($this->extractor(), $file);

        self::assertStringContainsString('# Quartalsbericht 2026', $text);
        self::assertStringContainsString('# Einfuehrung', $text);
        self::assertStringContainsString('## Kennzahlen', $text);
        self::assertStringContainsString('Der Umsatz stieg um zwoelf Prozent.', $text);
        self::assertStringNotContainsString('<script>', $text);
        self::assertStringNotContainsString('schmutz', $text);
        self::assertStringNotContainsString('color:red', $text);
    }

    public function testEpubKeepsChapterTitleAndHeadings(): void {
        $epub = $this->zipBytes([
            'OEBPS/chapter1.xhtml' => '<html><head><title>Kapitel Eins</title></head><body>'
                . '<h2>Abschnitt A</h2><p>Inhalt von Kapitel eins.</p>'
                . '</body></html>',
        ]);
        $file = $this->mockFile('buch.epub', 'application/epub+zip', $epub);
        $text = $this->invokeExtract($this->extractor(), $file);

        self::assertStringContainsString('# Kapitel Eins', $text);
        self::assertStringContainsString('## Abschnitt A', $text);
        self::assertStringContainsString('Inhalt von Kapitel eins.', $text);
    }

    public function testOdfKeepsSheetNames(): void {
        $ods = $this->zipBytes([
            'content.xml' => '<?xml version="1.0"?><office:document-content '
                . 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
                . 'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
                . 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
                . '<office:body><office:spreadsheet>'
                . '<table:table table:name="Kundendaten"><table:table-row><table:table-cell>'
                . '<text:p>Customer row one</text:p></table:table-cell></table:table-row></table:table>'
                . '</office:spreadsheet></office:body></office:document-content>',
        ]);
        $file = $this->mockFile('kunden.ods', 'application/vnd.oasis.opendocument.spreadsheet', $ods);
        $text = $this->invokeExtract($this->extractor(), $file);

        self::assertStringContainsString('[Sheet: Kundendaten]', $text);
        self::assertStringContainsString('Customer row one', $text);
    }

    /** @param array<int,string> $args */
    private function callPrivate(Indexer $indexer, string $method, array $args): mixed {
        $reflection = new ReflectionClass(Indexer::class);
        return $reflection->getMethod($method)->invokeArgs($indexer, $args);
    }

    // ---- Saved mail, web archives, mailboxes ----

    /**
     * A saved .eml is a MIME container: the readable text sits in an encoded
     * body, and the envelope carries what people actually search for.
     */
    public function testEmlYieldsEnvelopeAndQuotedPrintableBody(): void {
        $eml = "From: Anna Beispiel <anna@example.org>\n"
            . "To: team@example.org\n"
            . "Subject: Rechnung Mai\n"
            . "Date: Tue, 12 May 2026 09:30:00 +0200\n"
            . "Content-Type: text/plain; charset=utf-8\n"
            . "Content-Transfer-Encoding: quoted-printable\n"
            . "\n"
            . "Hallo Team,\n\ndie Rechnung f=C3=BCr Mai betr=C3=A4gt 1.200 Euro.\n";
        $file = $this->mockFile('rechnung.eml', 'message/rfc822', $eml);
        $text = $this->invokeExtract($this->extractor(), $file);

        self::assertStringContainsString('Subject: Rechnung Mai', $text);
        self::assertStringContainsString('anna@example.org', $text);
        self::assertStringContainsString('die Rechnung für Mai beträgt 1.200 Euro', $text);
    }

    /**
     * A multipart mail usually repeats itself as plain text and as HTML. The
     * plain part is used so the indexed text does not contain the answer twice.
     */
    public function testEmlMultipartPrefersPlainTextOverHtml(): void {
        $plain = base64_encode('Der Vertrag laeuft bis Dezember 2026.');
        $html = base64_encode('<html><body><p>Der Vertrag laeuft bis Dezember 2026.</p></body></html>');
        $eml = "Subject: Vertrag\n"
            . "Content-Type: multipart/alternative; boundary=\"BOUND\"\n"
            . "\n"
            . "--BOUND\nContent-Type: text/plain; charset=utf-8\nContent-Transfer-Encoding: base64\n\n{$plain}\n"
            . "--BOUND\nContent-Type: text/html; charset=utf-8\nContent-Transfer-Encoding: base64\n\n{$html}\n"
            . "--BOUND--\n";
        $file = $this->mockFile('vertrag.eml', 'message/rfc822', $eml);
        $text = $this->invokeExtract($this->extractor(), $file);

        self::assertStringContainsString('Der Vertrag laeuft bis Dezember 2026.', $text);
        self::assertSame(1, substr_count($text, 'Der Vertrag laeuft bis Dezember 2026.'));
        self::assertStringNotContainsString('<p>', $text);
    }

    /** An encoded-word subject (=?UTF-8?B?...?=) must be readable, not literal. */
    public function testEmlDecodesAnEncodedWordSubject(): void {
        $subject = base64_encode('Bestellung #4711 — Lieferung');
        $eml = "Subject: =?UTF-8?B?{$subject}?=\n"
            . "Content-Type: text/plain; charset=utf-8\n\nKurzer Text.\n";
        $file = $this->mockFile('bestellung.eml', 'message/rfc822', $eml);
        $text = $this->invokeExtract($this->extractor(), $file);

        self::assertStringContainsString('Bestellung #4711 — Lieferung', $text);
        self::assertStringNotContainsString('=?UTF-8?B?', $text);
    }

    /**
     * A mailbox holds many messages. All of them have to be indexed, not just
     * the first one whose envelope would otherwise swallow the file.
     */
    public function testMboxIndexesEveryMessage(): void {
        $mbox = "From anna@example.org Tue May 12 09:30:00 2026\n"
            . "Subject: Erste Nachricht\nContent-Type: text/plain\n\nText der ersten Nachricht.\n"
            . "\nFrom bernd@example.org Wed May 13 11:00:00 2026\n"
            . "Subject: Zweite Nachricht\nContent-Type: text/plain\n\nText der zweiten Nachricht.\n";
        $file = $this->mockFile('postfach.mbox', 'application/mbox', $mbox);
        $text = $this->invokeExtract($this->extractor(), $file);

        self::assertStringContainsString('Erste Nachricht', $text);
        self::assertStringContainsString('Text der ersten Nachricht.', $text);
        self::assertStringContainsString('Zweite Nachricht', $text);
        self::assertStringContainsString('Text der zweiten Nachricht.', $text);
    }

    /** A web archive is MIME too; its HTML part becomes readable text. */
    public function testMhtmlConvertsTheHtmlPartToText(): void {
        $html = '<html><head><title>Archivseite</title></head><body><h1>Spezifikation</h1>'
            . '<p>Die Schnittstelle liefert JSON.</p></body></html>';
        $mhtml = "From: <Saved by Browser>\n"
            . "Subject: Archivseite\n"
            . "Content-Type: multipart/related; boundary=\"Grenze\"\n"
            . "\n"
            . "--Grenze\nContent-Type: text/html; charset=utf-8\n\n{$html}\n"
            . "--Grenze--\n";
        $file = $this->mockFile('seite.mhtml', 'application/x-mimearchive', $mhtml);
        $text = $this->invokeExtract($this->extractor(), $file);

        self::assertStringContainsString('Spezifikation', $text);
        self::assertStringContainsString('Die Schnittstelle liefert JSON.', $text);
        self::assertStringNotContainsString('<p>', $text);
    }

    /**
     * A mail declared as Latin-1 must be stored as valid UTF-8: an invalid byte
     * sequence would fail the insert and cost the whole file.
     */
    public function testEmlConvertsLatin1BodiesToUtf8(): void {
        $latin1 = "Subject: Pruefung\nContent-Type: text/plain; charset=iso-8859-1\n\n"
            . "Pr\xFCfbericht f\xFCr die Anlage.\n";
        $file = $this->mockFile('pruefung.eml', 'message/rfc822', $latin1);
        $text = $this->invokeExtract($this->extractor(), $file);

        self::assertTrue(mb_check_encoding($text, 'UTF-8'), 'the extracted text must be valid UTF-8');
        self::assertStringContainsString('Prüfbericht für die Anlage.', $text);
    }

    /** A declared multipart without a usable boundary must not crash or hang. */
    public function testEmlWithBrokenBoundaryStillReturnsTheEnvelope(): void {
        $eml = "Subject: Kaputte Nachricht\nContent-Type: multipart/mixed\n\nkein Boundary vorhanden";
        $file = $this->mockFile('kaputt.eml', 'message/rfc822', $eml);
        $text = $this->invokeExtract($this->extractor(), $file);

        self::assertStringContainsString('Kaputte Nachricht', $text);
    }

    /** A deeply nested message must stop at the depth guard instead of recursing. */
    public function testDeeplyNestedMimeStopsAtTheDepthGuard(): void {
        $innermost = "Content-Type: text/plain\n\nTief verschachtelter Inhalt.\n";
        $payload = $innermost;
        for ($i = 0; $i < 8; $i++) {
            $payload = "Content-Type: multipart/mixed; boundary=\"B{$i}\"\n\n--B{$i}\n" . $payload . "\n--B{$i}--\n";
        }
        $file = $this->mockFile('tief.eml', 'message/rfc822', "Subject: Tief\n" . $payload);
        $text = $this->invokeExtract($this->extractor(), $file);

        self::assertStringContainsString('Tief', $text);
        // The guard stops before the innermost leaf, which is the point: a
        // crafted nest must not be walked without end.
        self::assertStringNotContainsString('Tief verschachtelter Inhalt.', $text);
    }

    // ---- Jupyter notebooks ----

    public function testNotebookKeepsMarkdownAndCodeApart(): void {
        $notebook = json_encode([
            'cells' => [
                ['cell_type' => 'markdown', 'source' => ["# Analyse\n", 'Ergebnis der Auswertung.']],
                ['cell_type' => 'code', 'source' => ["import pandas as pd\n", 'df.groupby("region").sum()']],
                ['cell_type' => 'raw', 'source' => ['Rohnotiz']],
                ['cell_type' => 'code', 'source' => ['   ']],
            ],
            'nbformat' => 4,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $file = $this->mockFile('analyse.ipynb', 'application/x-ipynb+json', (string)$notebook);
        $text = $this->invokeExtract($this->extractor(), $file);

        self::assertStringContainsString('[Markdown]', $text);
        self::assertStringContainsString('Ergebnis der Auswertung.', $text);
        self::assertStringContainsString('[Code]', $text);
        self::assertStringContainsString('df.groupby("region").sum()', $text);
        self::assertStringContainsString('Rohnotiz', $text);
    }

    /** A notebook that does not parse yields nothing instead of raw JSON noise. */
    public function testBrokenNotebookYieldsNoText(): void {
        $file = $this->mockFile('kaputt.ipynb', 'application/x-ipynb+json', '{"cells": [not json');
        self::assertSame('', $this->invokeExtract($this->extractor(), $file));
    }

    // ---- The new types must also pass the indexable check ----

    /** @return iterable<string,array{0:string,1:string}> */
    public static function newIndexableTypes(): iterable {
        yield 'eml by extension' => ['application/octet-stream', 'mail.eml'];
        yield 'eml by mime' => ['message/rfc822', 'mail.dat'];
        yield 'mhtml' => ['application/x-mimearchive', 'page.mhtml'];
        yield 'mht' => ['application/octet-stream', 'page.mht'];
        yield 'mbox by mime' => ['application/mbox', 'box.dat'];
        yield 'mbox by extension' => ['application/octet-stream', 'box.mbox'];
        yield 'ipynb by mime' => ['application/x-ipynb+json', 'nb.dat'];
        yield 'ipynb by extension' => ['application/octet-stream', 'nb.ipynb'];
        yield 'patch' => ['application/octet-stream', 'fix.patch'];
        yield 'terraform' => ['application/octet-stream', 'main.tf'];
        yield 'graphql' => ['application/octet-stream', 'schema.graphql'];
        yield 'kotlin' => ['application/octet-stream', 'App.kt'];
    }

    #[DataProvider('newIndexableTypes')]
    public function testNewDocumentTypesAreIndexable(string $mime, string $name): void {
        $indexable = $this->callPrivate($this->extractor(), 'isTextMime', [$mime, $name]);
        self::assertTrue($indexable, $name . ' (' . $mime . ') must be indexable');
    }
}
