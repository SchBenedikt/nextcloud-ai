<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;

/** Generates one confirmed image and stores it in the user's EVA folder. */
final class StickerToolExecutor implements DomainToolExecutor {
    public function __construct(private IRootFolder $rootFolder, private ?OpenAICompatible $imageProvider = null) {
    }

    public function tools(): array {
        return ['create_sticker'];
    }

    public function execute(string $tool, string $userId, array $args): array {
        if ($tool !== 'create_sticker') return ['ok' => false, 'error' => 'Unsupported sticker tool: ' . $tool];
        return $this->createSticker($this->userHome($userId), $args);
    }

    private function userHome(string $userId): ?Folder {
        try {
            if (PHP_SAPI === 'cli') \OC_Util::setupFS($userId);
            return $this->rootFolder->getUserFolder($userId);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Generate one confirmed sticker and keep it in the user's EVA folder. */
    private function createSticker(?Folder $home, array $args): array {
        if (!$home instanceof Folder || $this->imageProvider === null) {
            return ['ok' => false, 'error' => 'Sticker generation requires a configured OpenAI-compatible image provider.'];
        }
        $prompt = trim((string)($args['prompt'] ?? ''));
        if ($prompt === '') return ['ok' => false, 'error' => 'prompt required'];
        try {
            $images = $this->imageProvider->generateImages(
                'Create a single friendly sticker with a transparent background, bold clean outline, no watermark and no readable text: ' . mb_substr($prompt, 0, 1000),
                1,
                180,
            );
            $folder = $home->nodeExists('EVA') ? $home->get('EVA') : $home->newFolder('EVA');
            if (!$folder instanceof Folder) return ['ok' => false, 'error' => 'The EVA folder exists but is not a folder.'];
            $name = 'eva-sticker-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.png';
            $file = $folder->newFile($name, $images[0]['bytes']);
            return ['ok' => true, 'result' => ['path' => 'EVA/' . $name, 'file_id' => (int)$file->getId(), 'mime' => $images[0]['mime']]];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

}
