<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserManager;

/**
 * Creates a small, per-user identity section in KNOWLEDGE.md on first use.
 * Existing user-authored knowledge is never replaced.
 */
class KnowledgeInitializer {
    private const KNOWLEDGE_FILE = 'KNOWLEDGE.md';
    private const INITIALIZED = '1';
    private const MARKER = '<!-- eva_ai:profile-initialized -->';

    public function __construct(
        private AppConfig $config,
        private IRootFolder $rootFolder,
        private IUserManager $userManager
    ) {
    }

    public function ensureInitialized(string $userId): void {
        if ($userId === '') {
            return;
        }
        $this->config->setUserId($userId);
        if ($this->config->get('knowledge_initialized') === self::INITIALIZED) {
            return;
        }

        try {
            $user = $this->userManager->get($userId);
            if ($user === null) {
                return;
            }
            $home = $this->rootFolder->getUserFolder($userId);
            $content = '';
            $exists = $home->nodeExists(self::KNOWLEDGE_FILE);
            if ($exists) {
                $node = $home->get(self::KNOWLEDGE_FILE);
                if (!$node instanceof File) {
                    return;
                }
                $content = (string)$node->getContent();
                if (str_contains($content, self::MARKER)) {
                    $this->config->set('knowledge_initialized', self::INITIALIZED);
                    return;
                }
            }

            $profile = $this->profileSection($userId, $user->getDisplayName(), $user->getEMailAddress());
            $updated = rtrim($content);
            if ($updated !== '') {
                $updated .= "\n\n";
            }
            $updated .= $profile . "\n";

            if ($exists) {
                $home->get(self::KNOWLEDGE_FILE)->putContent($updated);
            } else {
                $home->newFile(self::KNOWLEDGE_FILE, $updated);
            }
            $this->config->set('knowledge_initialized', self::INITIALIZED);
        } catch (\Throwable $e) {
            // A transient VFS/profile failure must be retryable on the next app request.
        }
    }

    private function profileSection(string $userId, ?string $displayName, ?string $email): string {
        $lines = [self::MARKER, '## About me (from my Nextcloud profile)', ''];
        $lines[] = '- Nextcloud user ID: ' . $this->clean($userId);
        if ($this->clean($displayName) !== '') {
            $lines[] = '- Name: ' . $this->clean($displayName);
        }
        if ($this->clean($email) !== '') {
            $lines[] = '- Email: ' . $this->clean($email);
        }
        $lines[] = '- Imported automatically on ' . date('Y-m-d') . '. This section can be edited or deleted at any time.';
        return implode("\n", $lines);
    }

    private function clean(?string $value): string {
        $value = trim((string)$value);
        $value = preg_replace('/[\r\n\t]+/', ' ', $value) ?? '';
        return mb_substr($value, 0, 500);
    }
}
