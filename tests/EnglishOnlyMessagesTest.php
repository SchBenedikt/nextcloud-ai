<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression test for issue #190: English and German were mixed.
 *
 * The app's source language is English - `l10n/en.json` maps a key to itself and
 * `l10n/de.json` carries the German - so a German string written directly into
 * the PHP code can never be translated. It also shows up verbatim for an English
 * user: the TaskProcessing providers used to send German system prompts to the
 * model and throw German error messages into the Assistant UI.
 *
 * Comments are stripped before scanning, because the codebase has German
 * comments and this test is about what a user or the model can see.
 */
final class EnglishOnlyMessagesTest extends TestCase {
    /** Words that only appear in German prose, not in identifiers or English. */
    private const GERMAN_MARKERS = [
        ' der ', ' die ', ' das ', ' und ', ' oder ', ' nicht ', ' kein ', ' keine ',
        ' ist ', ' sind ', ' wird ', ' werden ', ' bitte ', ' muss ', ' mit ',
        ' für ', ' eine ', ' einen ', ' dem ', ' des ', ' sich ', ' auch ',
        ' Du bist', ' Gib nur', ' Schreibe ', ' Antworte ',
    ];

    /** @return list<string> */
    private function libraryFiles(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/lib')) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    /** Remove comments so German prose in a comment is not reported. */
    private function withoutComments(string $source): string
    {
        $source = (string)preg_replace('~/\*.*?\*/~s', '', $source);
        return (string)preg_replace('~^\s*//.*$~m', '', $source);
    }

    /**
     * An exception message reaches the user through the Assistant UI, so it has
     * to be in the source language.
     */
    public function testExceptionMessagesAreEnglish(): void
    {
        $offenders = [];
        foreach ($this->libraryFiles() as $file) {
            $source = $this->withoutComments((string)file_get_contents($file));
            preg_match_all('/(?:RuntimeException|ProcessingException|InvalidArgumentException|\\\Exception)\(\s*\'([^\']*)\'/', $source, $matches);
            foreach ($matches[1] as $message) {
                if ($this->looksGerman($message)) {
                    $offenders[] = basename($file) . ': ' . $message;
                }
            }
        }
        self::assertSame([], $offenders, 'exception messages must be English so they can be translated');
    }

    /**
     * Tool errors are handed to the model and often repeated to the user, so a
     * German error string is visible to everyone.
     */
    public function testToolErrorStringsAreEnglish(): void
    {
        $offenders = [];
        foreach ($this->libraryFiles() as $file) {
            $source = $this->withoutComments((string)file_get_contents($file));
            preg_match_all('/[\'"]error[\'"]\s*=>\s*[\'"]([^\'"]{4,})[\'"]/', $source, $matches);
            foreach ($matches[1] as $message) {
                if ($this->looksGerman($message)) {
                    $offenders[] = basename($file) . ': ' . $message;
                }
            }
        }
        self::assertSame([], $offenders, 'tool error strings must be English');
    }

    /**
     * The Assistant task providers instruct the model. Those instructions used
     * to be German, which pushed German phrasing into answers for every user.
     */
    public function testTaskProviderPromptsAreEnglish(): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__) . '/lib/TaskProcessing/*.php') ?: [] as $file) {
            $source = $this->withoutComments((string)file_get_contents($file));
            // A German instruction opener is the tell; the language picker in
            // EvaTranslateProvider legitimately shows native language names.
            if (preg_match('/[\'"]Du bist\b/', $source) === 1) {
                $offenders[] = basename($file) . ': German prompt opener';
            }
            preg_match_all('/[\'"]((?:Du|Gib|Schreibe|Antworte|Fasse|Formuliere|Verbessere|Formatiere|Ändere|Übersetze|Korrigiere|Erstelle|Extrahiere)[^\'"]{6,})[\'"]/', $source, $matches);
            foreach ($matches[1] as $message) {
                $offenders[] = basename($file) . ': ' . $message;
            }
        }
        self::assertSame([], $offenders, 'task provider prompts must be English');
    }

    /**
     * The Talk bot's own words reach the conversation, so they are part of the
     * app's visible language. They used to be hardcoded German, which an English
     * chat saw verbatim.
     */
    public function testTheTalkBotSpeaksEnglish(): void
    {
        $source = $this->withoutComments((string)file_get_contents(dirname(__DIR__) . '/lib/Listener/TalkBotListener.php'));
        $german = [
            'Du bist',
            'Ich kann leider',
            'Uups',
            'Bitte versuche',
            'Quellen:',
            'Hier sind meine Befehle',
            'In diesem Raum',
            'Leider habe ich gerade',
            'Das konnte ich leider nicht verstehen',
        ];
        foreach ($german as $phrase) {
            self::assertStringNotContainsString($phrase, $source, 'the Talk bot must answer in English: ' . $phrase);
        }
    }

    /** Every prompt should name its language rule, so the answer language is kept. */
    public function testTaskProviderPromptsKeepTheAnswerLanguageRule(): void
    {
        $missing = [];
        foreach (glob(dirname(__DIR__) . '/lib/TaskProcessing/Eva*Provider.php') ?: [] as $file) {
            $name = basename($file);
            // Providers that pass the prompt straight through (chat, text-to-text)
            // delegate their language handling elsewhere.
            if (in_array($name, ['EvaTextToTextProvider.php', 'EvaChangeToneProvider.php'], true)) {
                continue;
            }
            $source = $this->withoutComments((string)file_get_contents($file));
            if (preg_match('/same language as/i', $source) !== 1) {
                $missing[] = $name;
            }
        }
        self::assertNotContains(
            'EvaChangeToneProvider.php',
            $missing,
            'the tone provider must keep answering in the text language'
        );
        self::assertLessThanOrEqual(6, count($missing), 'prompts should state the answer language: ' . implode(', ', $missing));
    }

    private function looksGerman(string $text): bool
    {
        if (preg_match('/[äöüÄÖÜß]/u', $text) === 1) {
            return true;
        }
        $padded = ' ' . $text . ' ';
        foreach (self::GERMAN_MARKERS as $marker) {
            if (stripos($padded, $marker) !== false) {
                return true;
            }
        }
        return false;
    }
}
