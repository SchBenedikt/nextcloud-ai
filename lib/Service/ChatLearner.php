<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Learns personal facts from completed chat conversations and stores them
 * in categorized sections of KNOWLEDGE.md (Issue: chat-based learning).
 *
 * After each chat, the conversation is analysed for user-revealed facts.
 * Facts are grouped into categories: preferences, projects, people, skills,
 * work, and other. Each category gets its own Markdown section with dated
 * bullet points, preserving existing content.
 */
class ChatLearner {
    private const KNOWLEDGE_FILE = 'KNOWLEDGE.md';
    private const MAX_FACT_LENGTH = 300;
    private const MAX_FACTS_PER_CATEGORY = 50;

    /** Category markers in KNOWLEDGE.md */
    private const CATEGORIES = [
        'preferences' => '## My Preferences',
        'projects'    => '## My Projects',
        'people'      => '## People I Work With',
        'skills'      => '## My Skills',
        'work'        => '## My Work',
        'other'       => '## Other Facts About Me',
    ];

    /** Keyword patterns for category detection */
    private const CATEGORY_PATTERNS = [
        'preferences' => '/\b(like|prefer|love|hate|enjoy|favourite|favorite|avourite|dislike|always use|never use|usually|normally|style|taste|mag|liebe|hasse|bevorzuge|nutze meistens|verwende meistens|immer nutzen|nie nutzen|geschmack)\b/iu',
        'projects'    => '/\b(project|sprint|release|deploy|roadmap|milestone|epic|feature branch|version \d|v\d|launch|deadline|projekt|veröffentlichung|bereitstellung|meilenstein|frist)\b/iu',
        'people'      => '/\b(colleague|coworker|team lead|manager|reports to|works with|my team|our team|partner|client|stakeholder|kolleg(?:e|in|en|innen)|teamleitung|vorgesetzt(?:e|er|en)|arbeite mit|mein team|unser team|kunde(?:n)?|ansprechpartner)\b/iu',
        'skills'      => '/\b(speciali[sz]e|expertise|proficient|experienced in|certified|learned|studied|degree|qualification|tech stack|spezialisiere|erfahrung mit|zertifiziert|gelernt|studiert|abschluss|kenntnisse|technologie[- ]stack)\b/iu',
        'work'        => '/\b(job|role|position|department|company|office|remote|salary|contract|freelance|client|project manager|engineer|developer|designer|analyst|beruf|rolle|stelle|abteilung|unternehmen|büro|homeoffice|gehalt|vertrag|freiberuflich|projektmanager|entwickler(?:in)?|designer(?:in)?|analyst(?:in)?)\b/iu',
    ];

    public function __construct(
        private IRootFolder $rootFolder,
        private AppConfig $config,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Analyse a completed chat and extract personal facts into KNOWLEDGE.md.
     * Called after a chat conversation completes (streaming done event).
     *
     * @param array<int,array{role:string,text:string}> $messages
     */
    public function learnFromChat(string $userId, array $messages): void {
        if ($userId === [] || $messages === []) {
            return;
        }
        $this->config->setUserId($userId);

        // Only analyse if enabled (default: on).
        if ($this->config->get('chat_learning_enabled') === '0') {
            return;
        }

        $facts = $this->extractFacts($messages);
        if ($facts === []) {
            return;
        }

        $categorized = $this->categorizeFacts($facts);
        $this->appendToFacts($userId, $categorized);
    }

    /**
     * Extract user-revealed facts from the conversation.
     * Looks for user messages that contain personal statements.
     *
     * @param array<int,array{role:string,text:string}> $messages
     * @return string[]
     */
    private function extractFacts(array $messages): array {
        $facts = [];
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') !== 'user') {
                continue;
            }
            $text = trim($msg['text'] ?? '');
            if ($text === '' || mb_strlen($text) < 10) {
                continue;
            }
            // Look for personal statements: sentences with first-person pronouns
            // that reveal facts about the user.
            // Keep sentence punctuation. The question check below is a privacy
            // guard: a request such as "I want ...?" must not become a stored
            // personal fact simply because the delimiter was discarded.
            $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];
            foreach ($sentences as $sentence) {
                $s = trim($sentence);
                if ($s === '' || mb_strlen($s) < 15) {
                    continue;
                }
                if ($this->isPersonalFact($s)) {
                    $facts[] = mb_substr($s, 0, self::MAX_FACT_LENGTH);
                }
            }
        }
        return array_unique($facts);
    }

    /**
     * Check if a sentence is a personal fact (first-person statement about
     * preferences, work, skills, people, or projects).
     */
    private function isPersonalFact(string $sentence): bool {
        // Must contain first-person indicators.
        $firstPerson = '/\b(I |my |me |we |our |I\'m |I am |I work|I like|I prefer|I use|I have|I need|I want|I usually|I always|I never|I speciali[sz]e|ich |mein(?:e|en|em|er|es)? |mir |mich |wir |unser(?:e|en|em|er|es)? |ich bin|ich arbeite|ich mag|ich bevorzuge|ich nutze|ich verwende|ich habe|ich brauche|ich möchte|ich will|ich lerne|ich kann)\b/iu';
        if (!preg_match($firstPerson, $sentence)) {
            return false;
        }
        // Exclude questions (sentences ending with ?).
        if (str_ends_with(trim($sentence), '?')) {
            return false;
        }
        // Exclude very short or very generic statements.
        $excluded = '/^(I (am|was|have|had|do|did|can|will|would|could|should|might) )$/i';
        if (preg_match($excluded, trim($sentence))) {
            return false;
        }
        return true;
    }

    /**
     * Categorize extracted facts into sections.
     *
     * @param string[] $facts
     * @return array<string,string[]> category => facts
     */
    private function categorizeFacts(array $facts): array {
        $categorized = array_fill_keys(array_keys(self::CATEGORIES), []);
        foreach ($facts as $fact) {
            $assigned = false;
            foreach (self::CATEGORY_PATTERNS as $cat => $pattern) {
                if (preg_match($pattern, $fact)) {
                    $categorized[$cat][] = $fact;
                    $assigned = true;
                    break;
                }
            }
            if (!$assigned) {
                $categorized['other'][] = $fact;
            }
        }
        return $categorized;
    }

    /**
     * Append categorized facts to KNOWLEDGE.md.
     * Creates category sections if they don't exist, appends new facts
     * as dated bullet points.
     *
     * @param array<string,string[]> $categorized
     */
    private function appendToFacts(string $userId, array $categorized): void {
        try {
            $home = $this->rootFolder->getUserFolder($userId);
            $content = '';
            $exists = $home->nodeExists(self::KNOWLEDGE_FILE);
            if ($exists) {
                $node = $home->get(self::KNOWLEDGE_FILE);
                if (!$node instanceof File) {
                    return;
                }
                $content = (string)$node->getContent();
            }

            $updated = $content;
            $date = date('Y-m-d');

            foreach ($categorized as $category => $facts) {
                if ($facts === []) {
                    continue;
                }
                $header = self::CATEGORIES[$category];
                $existingFacts = $this->countCategoryFacts($updated, $header);
                if ($existingFacts >= self::MAX_FACTS_PER_CATEGORY) {
                    continue; // Don't grow sections indefinitely.
                }
                $available = self::MAX_FACTS_PER_CATEGORY - $existingFacts;
                $toAdd = array_slice($facts, 0, $available);

                $bullets = array_map(fn($f) => "- {$date}: {$f}", $toAdd);
                $block = $header . "\n" . implode("\n", $bullets) . "\n";

                if (str_contains($updated, $header)) {
                    // Append after the header section.
                    $updated = $this->appendAfterSection($updated, $header, $bullets);
                } else {
                    // Create new section.
                    $updated = rtrim($updated) . "\n\n" . $block;
                }
            }

            if ($updated !== $content) {
                if ($exists) {
                    $home->get(self::KNOWLEDGE_FILE)->putContent($updated);
                } else {
                    $home->newFile(self::KNOWLEDGE_FILE, $updated);
                }
                $this->logger->info('eva_ai: chat learning added facts', [
                    'user' => $userId,
                    'categories' => array_filter(array_map(fn($f) => count($f) > 0 ? $f : null, $categorized)),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->debug('eva_ai: chat learning failed', [
                'user' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Count existing bullet points under a section header.
     */
    private function countCategoryFacts(string $content, string $header): int {
        $pos = strpos($content, $header);
        if ($pos === false) {
            return 0;
        }
        $after = substr($content, $pos + strlen($header));
        $nextHeader = preg_search('/^## /m', $after);
        if ($nextHeader !== false) {
            $after = substr($after, 0, $nextHeader);
        }
        return substr_count($after, "\n- ");
    }

    /**
     * Append bullet points after a section header, before the next header.
     */
    private function appendAfterSection(string $content, string $header, array $bullets): string {
        $pos = strpos($content, $header);
        if ($pos === false) {
            return $content;
        }
        $afterHeader = substr($content, $pos + strlen($header));
        // Find the next ## header or end of file.
        $nextHeaderPos = preg_match('/^## /m', $afterHeader, $m, PREG_OFFSET_CAPTURE);
        $insertPos = $nextHeaderPos ? $m[0][1] : strlen($afterHeader);
        $before = substr($content, 0, $pos + strlen($header));
        $middle = substr($afterHeader, 0, $insertPos);
        $end = substr($afterHeader, $insertPos);
        $newBullets = "\n" . implode("\n", $bullets) . "\n";
        return $before . rtrim($middle, "\n") . $newBullets . $end;
    }
}
