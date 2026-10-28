<?php
declare(strict_types=1);

namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Service\{EmailService, TalkTranscriptService, Indexer};
use OCP\{IUserManager, IUser};
use OCP\App\IAppManager;
use OCP\Files\{IRootFolder, File};

/** Fresh native-source checks without embedding or content extraction. */
class VectorSourceAccess {
    public function __construct(private IUserManager $users, private IAppManager $apps, private IRootFolder $files,
        private EmailService $mail, private TalkTranscriptService $talk) {}

    public function assertOwner(string $userId): IUser {
        $user = $this->users->get($userId);
        if ($user === null || $user->getUID() !== $userId || !$user->isEnabled()) throw new VectorStoreException('source_unavailable');
        return $user;
    }

    public function assertAccessible(string $userId, array $point): void {
        $user = $this->assertOwner($userId);
        $allowed = false;
        try {
            if ($point['source'] === 'files' && $point['file_id'] > 0) {
                $home = $this->files->getUserFolder($userId);
                foreach ($home->getById($point['file_id']) as $node) {
                    if ($node instanceof File && $node->isReadable()
                        && ($relative = $home->getRelativePath($node->getPath())) !== null
                        && ltrim($relative, '/') === $point['path']
                        && (int)$node->getMTime() === $point['source_mtime'] && (int)$node->getSize() === $point['source_size']) {
                        $allowed = true; break;
                    }
                }
            } elseif ($point['source'] === 'mail' && preg_match('~^mail://([1-9][0-9]{0,17})$~D', $point['path'], $m)) {
                $id = (int)$m[1];
                $allowed = $point['file_id'] === -$id && $this->apps->isEnabledForUser('mail', $user) && $this->mail->messageAccessible($userId, $id);
            } elseif ($point['source'] === 'talk' && preg_match('~^talk://([1-9][0-9]{0,17})$~D', $point['path'], $m)) {
                $id = (int)$m[1];
                $allowed = $point['file_id'] === Indexer::talkFileId($id) && $this->apps->isEnabledForUser('spreed', $user) && $this->talk->isMember($userId, $id);
            }
        } catch (\Throwable) { $allowed = false; }
        if (!$allowed) throw new VectorStoreException('source_unavailable');
    }
}
