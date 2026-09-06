<?php

declare(strict_types=1);
namespace OCA\EvaAi\Service;

use OCP\Files\File;

/** Bounded Office parser. XML is data only; no entity expansion or external access. */
class DocumentExtractor {
    public const OFFICE = ['docx','docm','dotx','dotm','xlsx','xlsm','xltx','xltm','pptx','pptm','ppsx','ppsm','potx','potm',
        'odt','ods','odp','odg','odf','ott','ots','otp','sxw','sxc','sxi','sxd','epub','doc','xls','ppt'];
    private const MAX_BYTES = 33554432;
    private int $bytes = 0;
    private \ZipArchive $zip;

    public function extract(File $file): string {
        $tmp = tempnam(sys_get_temp_dir(), 'eva_extract_');
        if ($tmp === false) { throw new \RuntimeException('Cannot create extraction file'); }
        try {
            $stream = $file->fopen('r');
            $out = fopen($tmp, 'wb');
            if (!is_resource($stream) || !is_resource($out)) { throw new \RuntimeException('File stream unavailable'); }
            try { stream_copy_to_stream($stream, $out, self::MAX_BYTES + 1); }
            finally { fclose($stream); fclose($out); }
            if (filesize($tmp) > self::MAX_BYTES) { throw new \RuntimeException('Office extraction exceeds the 32 MiB source limit'); }
            return $this->extractPath($tmp, strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)));
        } finally { @unlink($tmp); }
    }

    public function extractPath(string $path, string $extension): string {
        if (in_array($extension, ['doc','xls','ppt'], true)) { return SystemExtractor::legacy($path, $extension); }
        $this->bytes = 0;
        $this->zip = new \ZipArchive();
        if ($this->zip->open($path) !== true) { throw new \RuntimeException('Invalid Office archive'); }
        try {
            if ($this->zip->numFiles > 5000) { throw new \RuntimeException('Office archive has too many entries'); }
            if (in_array($extension, ['xlsx','xlsm','xltx','xltm'], true)) { return $this->spreadsheet(); }
            $entries = [];
            for ($i = 0; $i < $this->zip->numFiles; $i++) {
                $name = $this->zip->getNameIndex($i);
                if (preg_match('~^word/(document|header\d+|footer\d+|footnotes|endnotes|comments)\.xml$~', $name)
                    || preg_match('~^ppt/(slides/slide|notesSlides/notesSlide)\d+\.xml$~', $name)
                    || in_array($name, ['content.xml', 'styles.xml'], true)
                    || ($extension === 'epub' && preg_match('/\.(xhtml|html|htm)$/i', $name))) { $entries[] = $name; }
            }
            natsort($entries);
            $out = '';
            foreach ($entries as $entry) {
                $xml = $this->xml($entry);
                if ($xml === null) { continue; }
                $out .= "\n\n# Source: " . $entry . "\n" . $this->structuredText($xml);
                if (strlen($out) > self::MAX_BYTES) { throw new \RuntimeException('Extracted text exceeds 32 MiB'); }
            }
            return trim($out);
        } finally { $this->zip->close(); }
    }

    private function xml(string $name): ?\DOMDocument {
        $stat = $this->zip->statName($name);
        if ($stat === false) { return null; }
        $this->bytes += (int)$stat['size'];
        if ($this->bytes > self::MAX_BYTES) { throw new \RuntimeException('Office XML exceeds 32 MiB'); }
        $raw = $this->zip->getFromName($name);
        if ($raw === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $raw)) { throw new \RuntimeException('Unsafe XML declaration'); }
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$doc->loadXML($raw, LIBXML_NONET | LIBXML_NOBLANKS)) { throw new \RuntimeException('Malformed Office XML'); }
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        return $doc;
    }

    private function structuredText(\DOMNode $node): string {
        if ($node instanceof \DOMText) { return $node->nodeValue; }
        $name = $node->localName;
        if (in_array($name, ['script','style','annotation-end'], true)) { return ''; }
        $text = '';
        foreach ($node->childNodes as $child) { $text .= $this->structuredText($child); }
        if (in_array($name, ['p','h','h1','h2','h3','h4','h5','h6','tr','table-row'], true)) { return $text . "\n"; }
        if (in_array($name, ['tc','td','th','table-cell'], true)) { return trim($text) . " | "; }
        if (in_array($name, ['br','line-break'], true)) { return "\n"; }
        if ($name === 'tab') { return "\t"; }
        if ($name === 's' && $node instanceof \DOMElement) {
            return str_repeat(' ', min(20, max(1, (int)$node->getAttribute('text:c'))));
        }
        return $text;
    }

    private function spreadsheet(): string {
        $shared = []; $strings = $this->xml('xl/sharedStrings.xml');
        if ($strings !== null) {
            foreach ((new \DOMXPath($strings))->query('//*[local-name()="si"]') as $item) { $shared[] = $item->textContent; }
        }
        $workbook = $this->xml('xl/workbook.xml');
        $rels = $this->xml('xl/_rels/workbook.xml.rels');
        if ($workbook === null || $rels === null) { throw new \RuntimeException('Workbook metadata missing'); }
        $targets = [];
        foreach ($rels->getElementsByTagName('Relationship') as $r) {
            if ($r->getAttribute('TargetMode') !== 'External') { $targets[$r->getAttribute('Id')] = $r->getAttribute('Target'); }
        }
        $out = '';
        foreach ((new \DOMXPath($workbook))->query('//*[local-name()="sheet"]') as $sheet) {
            $target = $targets[$sheet->getAttribute('r:id')] ?? '';
            if ($target === '' || str_contains($target, '..')) { continue; }
            $entry = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            $xml = $this->xml($entry);
            if ($xml === null) { continue; }
            $out .= "\n\n# Sheet: " . $sheet->getAttribute('name') . "\n";
            $xp = new \DOMXPath($xml);
            foreach ($xp->query('//*[local-name()="row"]') as $row) {
                $cells = [];
                foreach ($xp->query('./*[local-name()="c"]', $row) as $cell) {
                    $value = (string)$xp->evaluate('string(./*[local-name()="v"])', $cell);
                    if ($cell->getAttribute('t') === 's') { $value = $shared[(int)$value] ?? ''; }
                    if ($cell->getAttribute('t') === 'inlineStr') { $value = (string)$xp->evaluate('string(./*[local-name()="is"])', $cell); }
                    $formula = (string)$xp->evaluate('string(./*[local-name()="f"])', $cell);
                    if ($formula !== '') { $value .= ' [formula: ' . $formula . ']'; }
                    $cells[] = $cell->getAttribute('r') . ': ' . $value;
                }
                $out .= implode(' | ', $cells) . "\n";
            }
        }
        return trim($out);
    }
}
