<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;

/**
 * Per-room Talk bot state (Issue #85).
 *
 * Stores whether EVA answers in a given Talk room. The bot is enabled by
 * default; `/stop` disables it for the room and `/start` re-enables it.
 * State lives in app data (eva_ai/talk/rooms.json) so it survives restarts
 * and is shared by every user in the room.
 */
class TalkRoomState {
    public const FILE = 'rooms.json';

    public function __construct(
        private IAppDataFactory $appDataFactory,
        private LoggerInterface $logger
    ) {
    }

    public function isEnabled(int $roomId): bool {
        if ($roomId <= 0) {
            return true;
        }
        $state = $this->read();
        return !isset($state[$roomId]['enabled']) || (bool)$state[$roomId]['enabled'];
    }

    public function setEnabled(int $roomId, bool $enabled): void {
        if ($roomId <= 0) {
            return;
        }
        $state = $this->read();
        $state[$roomId] = ['enabled' => $enabled, 'changedAt' => time()];
        $this->write($state);
    }

    /** @return array<int,array{enabled:bool,changedAt:int}> */
    private function read(): array {
        try {
            $file = $this->file();
            if (!$file->exists()) {
                return [];
            }
            $decoded = json_decode((string)$file->getContent(), true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: unable to read Talk room state', ['exception' => $e->getMessage()]);
            return [];
        }
    }

    /** @param array<int,array{enabled:bool,changedAt:int}> $state */
    private function write(array $state): void {
        try {
            $this->file()->putContent(json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: unable to persist Talk room state', ['exception' => $e->getMessage()]);
        }
    }

    private function file(): ISimpleFile {
        $appData = $this->appDataFactory->get('eva_ai');
        $folder = $this->folder($appData);
        if (!$folder->fileExists(self::FILE)) {
            $folder->newFile(self::FILE);
        }
        return $folder->getFile(self::FILE);
    }

    private function folder(\OCP\Files\AppData\IAppData $appData): ISimpleFolder {
        $folder = $appData->getFolder('talk');
        if (!$folder->fileExists(self::FILE)) {
            // newFile is called lazily by file(); nothing to do here.
        }
        return $folder;
    }
}