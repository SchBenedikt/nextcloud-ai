<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\Server;

/** Handles comments, tags, and file version metadata tools. */
final class FileMetadataToolExecutor implements DomainToolExecutor {
    public function __construct(
        private IRootFolder $rootFolder,
        private IUserManager $userManager,
        private AppConfig $config,
        private ?\OCP\Comments\ICommentsManagerFactory $commentsFactory = null,
        private ?\OCP\SystemTag\ISystemTagManagerFactory $systemTagFactory = null,
    ) {
    }

    public function tools(): array {
        return ['list_comments', 'add_comment', 'delete_comment', 'list_system_tags', 'tag_file', 'untag_file', 'list_file_versions', 'restore_file_version'];
    }

    public function execute(string $tool, string $userId, array $args): array {
        return match ($tool) {
            'list_comments' => $this->listComments($args),
            'add_comment' => $this->addComment($userId, $args),
            'delete_comment' => $this->deleteComment($args),
            'list_system_tags' => $this->listSystemTags($args),
            'tag_file' => $this->tagFile($userId, $args, false),
            'untag_file' => $this->tagFile($userId, $args, true),
            'list_file_versions' => $this->listFileVersions($this->userHome($userId), $args),
            'restore_file_version' => $this->restoreFileVersion($this->userHome($userId), $args),
            default => ['ok' => false, 'error' => 'Unsupported file metadata tool: ' . $tool],
        };
    }

    private function userHome(string $userId): ?Folder {
        try {
            if (PHP_SAPI === 'cli') \OC_Util::setupFS($userId);
            return $this->rootFolder->getUserFolder($userId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function commentsManager(): ?\OCP\Comments\ICommentsManager {
        try {
            $factory = $this->commentsFactory ?? Server::get(\OCP\Comments\ICommentsManagerFactory::class);
            return $factory->getManager();
        } catch (\Throwable) {
            return null;
        }
    }

    private function systemTagServices(): ?array {
        try {
            $factory = $this->systemTagFactory ?? Server::get(\OCP\SystemTag\ISystemTagManagerFactory::class);
            return ['manager' => $factory->getManager(), 'mapper' => $factory->getObjectMapper()];
        } catch (\Throwable) { return null; }
    }

    private function listSystemTags(array $args): array {
        $services = $this->systemTagServices();
        if ($services === null) return ['ok' => false, 'error' => 'Nextcloud system tags are not available.'];
        try {
            $search = trim((string)($args['search'] ?? ''));
            $user = $this->userManager->get($this->config->userId() ?? '');
            $tags = $services['manager']->getAllTags(true, $search !== '' ? '%' . $search . '%' : null);
            $out = [];
            foreach ($tags as $tag) {
                if ($user !== null && !$services['manager']->canUserSeeTag($tag, $user)) continue;
                $out[] = ['id' => (string)$tag->getId(), 'name' => (string)$tag->getName(), 'user_visible' => (bool)$tag->isUserVisible(), 'user_assignable' => (bool)$tag->isUserAssignable(), 'color' => method_exists($tag, 'getColor') ? (string)$tag->getColor() : null];
            }
            return ['ok' => true, 'result' => ['tags' => $out]];
        } catch (\Throwable) { return ['ok' => false, 'error' => 'System tags could not be read.']; }
    }

    private function tagFile(string $userId, array $args, bool $remove): array {
        $services = $this->systemTagServices();
        $fileId = trim((string)($args['file_id'] ?? '')); $name = trim((string)($args['tag'] ?? ''));
        if ($services === null) return ['ok' => false, 'error' => 'Nextcloud system tags are not available.'];
        if (!ctype_digit($fileId) || (int)$fileId < 1 || $name === '') return ['ok' => false, 'error' => 'A numeric file_id and non-empty tag are required'];
        try {
            $user = $this->userManager->get($userId);
            if ($user === null) return ['ok' => false, 'error' => 'User not found'];
            $tag = $services['manager']->getTag($name, true, true);
            if (!$services['manager']->canUserAssignTag($tag, $user)) return ['ok' => false, 'error' => 'The user may not assign this system tag.'];
            if ($remove) $services['mapper']->unassignTags($fileId, 'files', (string)$tag->getId());
            else $services['mapper']->assignTags($fileId, 'files', (string)$tag->getId());
            return ['ok' => true, 'result' => ['file_id' => (int)$fileId, 'tag' => (string)$tag->getName(), 'removed' => $remove]];
        } catch (\Throwable) { return ['ok' => false, 'error' => 'The system tag could not be changed. Check file access and tag permissions.']; }
    }

    private function versionManager(): ?object {
        $class = '\\OCA\\Files_Versions\\Versions\\IVersionManager';
        if (!interface_exists($class)) return null;
        try { return Server::get($class); } catch (\Throwable) { return null; }
    }

    private function fileById(?Folder $home, array $args): ?File {
        $id = trim((string)($args['file_id'] ?? ''));
        if ($home === null || !ctype_digit($id) || (int)$id < 1) return null;
        try {
            foreach ($home->getById((int)$id) as $node) if ($node instanceof File) return $node;
        } catch (\Throwable) { }
        return null;
    }

    private function listFileVersions(?Folder $home, array $args): array {
        $manager = $this->versionManager();
        $file = $this->fileById($home, $args);
        if ($manager === null) return ['ok' => false, 'error' => 'The Nextcloud files versions app is not available.'];
        if ($file === null) return ['ok' => false, 'error' => 'File not found or not accessible.'];
        try {
            $user = $this->userManager->get($this->config->userId() ?? '');
            if ($user === null) return ['ok' => false, 'error' => 'User not found.'];
            $versions = $manager->getVersionsForFile($user, $file);
            $out = [];
            foreach ($versions as $version) {
                $out[] = [
                    'version_id' => (string)$version->getRevisionId(),
                    'timestamp' => $version->getTimestamp(),
                    'date' => gmdate(DATE_ATOM, $version->getTimestamp()),
                    'size' => $version->getSize(),
                    'name' => $version->getSourceFileName(),
                    'mime_type' => $version->getMimeType(),
                ];
            }
            return ['ok' => true, 'result' => ['file_id' => (int)$file->getId(), 'path' => $file->getPath(), 'versions' => $out]];
        } catch (\Throwable) { return ['ok' => false, 'error' => 'File versions could not be read.']; }
    }

    private function restoreFileVersion(?Folder $home, array $args): array {
        $manager = $this->versionManager();
        $file = $this->fileById($home, $args);
        $revision = trim((string)($args['version_id'] ?? ''));
        if ($manager === null) return ['ok' => false, 'error' => 'The Nextcloud files versions app is not available.'];
        if ($file === null || $revision === '') return ['ok' => false, 'error' => 'A valid file_id and version_id are required.'];
        try {
            $user = $this->userManager->get($this->config->userId() ?? '');
            if ($user === null) return ['ok' => false, 'error' => 'User not found.'];
            $version = null;
            foreach ($manager->getVersionsForFile($user, $file) as $candidate) {
                if ((string)$candidate->getRevisionId() === $revision) { $version = $candidate; break; }
            }
            if ($version === null) return ['ok' => false, 'error' => 'That version does not belong to this file or is no longer available.'];
            $manager->rollback($version);
            return ['ok' => true, 'result' => ['file_id' => (int)$file->getId(), 'version_id' => $revision, 'restored' => true]];
        } catch (\Throwable) { return ['ok' => false, 'error' => 'The file version could not be restored. Check locks and permissions.']; }
    }

    private function commentData(\OCP\Comments\IComment $comment): array {
        return [
            'id' => (string)$comment->getId(),
            'parent_id' => (string)$comment->getParentId(),
            'object_type' => (string)$comment->getObjectType(),
            'object_id' => (string)$comment->getObjectId(),
            'actor_type' => (string)$comment->getActorType(),
            'actor_id' => (string)$comment->getActorId(),
            'message' => (string)$comment->getMessage(),
            'created' => $comment->getCreationDateTime()?->format(DATE_ATOM),
        ];
    }

    private function listComments(array $args): array {
        $manager = $this->commentsManager();
        if ($manager === null) return ['ok' => false, 'error' => 'The Nextcloud comments app/service is not available.'];
        $type = trim((string)($args['object_type'] ?? 'files'));
        $id = trim((string)($args['object_id'] ?? ''));
        if ($type === '' || $id === '') return ['ok' => false, 'error' => 'object_type and object_id are required'];
        $limit = max(1, min(100, (int)($args['limit'] ?? 50)));
        try {
            $comments = $manager->getForObject($type, $id, $limit, 0);
            return ['ok' => true, 'result' => ['comments' => array_map(fn($comment): array => $this->commentData($comment), $comments), 'object_type' => $type, 'object_id' => $id]];
        } catch (\Throwable $e) { return ['ok' => false, 'error' => 'Comments could not be read.']; }
    }

    private function addComment(string $userId, array $args): array {
        $manager = $this->commentsManager();
        if ($manager === null) return ['ok' => false, 'error' => 'The Nextcloud comments app/service is not available.'];
        $type = trim((string)($args['object_type'] ?? 'files')); $id = trim((string)($args['object_id'] ?? '')); $message = trim((string)($args['message'] ?? ''));
        if ($type === '' || $id === '' || $message === '') return ['ok' => false, 'error' => 'object_type, object_id and message are required'];
        try {
            $comment = $manager->create('users', $userId, $type, $id);
            $comment->setMessage(mb_substr($message, 0, \OCP\Comments\IComment::MAX_MESSAGE_LENGTH));
            if (trim((string)($args['parent_id'] ?? '')) !== '') $comment->setParentId(trim((string)$args['parent_id']));
            $saved = $manager->save($comment);
            return ['ok' => true, 'result' => $this->commentData($saved)];
        } catch (\Throwable) { return ['ok' => false, 'error' => 'Comment could not be added. Check object access and comment length.']; }
    }

    private function deleteComment(array $args): array {
        $manager = $this->commentsManager(); $id = trim((string)($args['comment_id'] ?? ''));
        if ($manager === null) return ['ok' => false, 'error' => 'The Nextcloud comments app/service is not available.'];
        if ($id === '') return ['ok' => false, 'error' => 'comment_id is required'];
        try { $manager->delete($id); return ['ok' => true, 'result' => ['comment_id' => $id, 'deleted' => true]]; }
        catch (\Throwable) { return ['ok' => false, 'error' => 'Comment could not be deleted. Check ownership and permissions.']; }
    }

}
