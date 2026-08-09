<?php

declare(strict_types=1);

namespace OCA\RagChat\Service;

use OCP\Files\IRootFolder;
use OCP\Share\IManager as ShareManager;
use OCP\Share\IShare;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Constants;

/**
 * Share management for the AI: list shares (outgoing + incoming), create
 * link/user shares, update expiration/note and delete shares.
 */
class SharesService {
    public function __construct(
        private ShareManager $shareManager,
        private IRootFolder $rootFolder
    ) {
    }

    /** @return array{ok:true,result:array}|array{ok:false,error:string} */
    public function list(string $userId, array $args = []): array {
        $perUser = max(1, (int)($args['limit'] ?? 100));
        $outgoing = [];
        foreach ([IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_LINK, IShare::TYPE_EMAIL, IShare::TYPE_CIRCLE, IShare::TYPE_ROOM] as $type) {
            try {
                $shares = $this->shareManager->getSharesBy($userId, $type, null, true, $perUser, 0);
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($shares as $share) {
                $outgoing[] = $this->describe($share, 'outgoing');
            }
        }
        $incoming = [];
        foreach ([IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_ROOM] as $type) {
            try {
                $shares = $this->shareManager->getSharedWith($userId, $type, $perUser, 0);
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($shares as $share) {
                $incoming[] = $this->describe($share, 'incoming');
            }
        }
        usort($outgoing, static fn($a, $b) => strcmp((string)$a['path'], (string)$b['path']));
        usort($incoming, static fn($a, $b) => strcmp((string)$a['path'], (string)$b['path']));
        return ['ok' => true, 'result' => ['outgoing' => $outgoing, 'incoming' => $incoming]];
    }

    /**
     * Create a share for a file/folder of the user.
     * @return array{ok:true,result:array}|array{ok:false,error:string}
     */
    public function create(string $userId, array $args): array {
        $path = trim((string)($args['path'] ?? ''));
        $type = strtolower((string)($args['type'] ?? 'link'));
        if ($path === '') {
            return ['ok' => false, 'error' => 'path required, e.g. "Documents/Plan.pdf"'];
        }
        $home = $this->rootFolder->getUserFolder($userId);
        try {
            $node = $home->get(ltrim($path, '/'));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'File/folder not found: ' . $path];
        }
        if (!$node->getFileInfo()->isMounted() && $node->getOwner()?->getUID() !== $userId) {
            return ['ok' => false, 'error' => 'You can only share your own files'];
        }

        $share = $this->shareManager->newShare();
        $share->setNode($node);
        $share->setShareOwner($userId);
        $share->setSharedBy($userId);
        $permissions = Constants::PERMISSION_READ;
        if (!empty($args['write'])) {
            $permissions |= Constants::PERMISSION_UPDATE;
        }
        if (!empty($args['share'])) {
            $permissions |= Constants::PERMISSION_SHARE;
        }
        $share->setPermissions($permissions);

        if ($type === 'link' || $type === 'public') {
            $share->setShareType(IShare::TYPE_LINK);
            if (!empty($args['password'])) {
                try {
                    $share->setPassword((string)$args['password']);
                } catch (\Throwable $e) {
                    return ['ok' => false, 'error' => 'Password rejected: ' . $e->getMessage()];
                }
            }
        } elseif ($type === 'user' || $type === 'internal') {
            $target = trim((string)($args['target'] ?? ''));
            if ($target === '') {
                return ['ok' => false, 'error' => 'target user id required for user shares'];
            }
            $share->setShareType(IShare::TYPE_USER);
            $share->setSharedWith($target);
        } elseif ($type === 'group') {
            $target = trim((string)($args['target'] ?? ''));
            if ($target === '') {
                return ['ok' => false, 'error' => 'target group id required for group shares'];
            }
            $share->setShareType(IShare::TYPE_GROUP);
            $share->setSharedWith($target);
        } else {
            return ['ok' => false, 'error' => 'Unsupported share type: ' . $type . ' (use link, user or group)'];
        }

        if (!empty($args['expiration'])) {
            try {
                $exp = new \DateTimeImmutable((string)$args['expiration']);
                $share->setExpirationDate($exp);
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'Invalid expiration date (use ISO like 2026-12-31)'];
            }
        }
        $note = trim((string)($args['note'] ?? ''));
        if ($note !== '') {
            try {
                $share->setNote($note);
            } catch (\Throwable $e) {
                // note not supported in this NC version - ignore
            }
        }

        try {
            $share = $this->shareManager->createShare($share);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Share failed: ' . $e->getMessage()];
        }
        return ['ok' => true, 'result' => $this->describe($share, 'outgoing')];
    }

    /** @return array{ok:true,result:array}|array{ok:false,error:string} */
    public function update(string $userId, array $args): array {
        $id = trim((string)($args['share_id'] ?? ''));
        if ($id === '') {
            return ['ok' => false, 'error' => 'share_id required (ocinternal: id or plain id)'];
        }
        $share = $this->findOwnShare($userId, $id);
        if ($share === null) {
            return ['ok' => false, 'error' => 'Share not found (or owned by someone else)'];
        }
        if (array_key_exists('note', $args) && method_exists($share, 'setNote')) {
            try {
                $share->setNote((string)$args['note']);
            } catch (\Throwable $e) {
            }
        }
        if (!empty($args['expiration'])) {
            try {
                $share->setExpirationDate(new \DateTimeImmutable((string)$args['expiration']));
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'Invalid expiration date'];
            }
        }
        if (!empty($args['permissions'])) {
            $perms = 0;
            $parts = array_map('trim', explode(',', (string)$args['permissions']));
            foreach ($parts as $part) {
                $perms |= match ($part) {
                    'read' => Constants::PERMISSION_READ,
                    'write', 'update' => Constants::PERMISSION_UPDATE,
                    'create' => Constants::PERMISSION_CREATE,
                    'delete' => Constants::PERMISSION_DELETE,
                    'share', 'reshare' => Constants::PERMISSION_SHARE,
                    default => 0,
                };
            }
            if ($perms !== 0) {
                $share->setPermissions($perms);
            }
        }
        try {
            $this->shareManager->updateShare($share);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Share update failed: ' . $e->getMessage()];
        }
        return ['ok' => true, 'result' => ['share' => $this->describe($share, 'outgoing')]];
    }

    /** @return array{ok:true,result:string}|array{ok:false,error:string} */
    public function delete(string $userId, array $args): array {
        $id = trim((string)($args['share_id'] ?? ''));
        if ($id === '') {
            return ['ok' => false, 'error' => 'share_id required'];
        }
        $share = $this->findOwnShare($userId, $id);
        if ($share === null) {
            return ['ok' => false, 'error' => 'Share not found (not yours)'];
        }
        try {
            $this->shareManager->deleteShare($share);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Share delete failed: ' . $e->getMessage()];
        }
        return ['ok' => true, 'result' => 'Deleted share ' . $share->getId() . ' of ' . $share->getNode()->getPath()];
    }

    /** @return array{path:string,type:string,recipient:string,token:string,url:string,expiration:?string,note:string,permissions:int} */
    private function describe(IShare $share, string $direction): array {
        $path = '/';
        try {
            $path = $share->getNode()->getPath();
            // strip to relative: /user/files/...
            $pos = strpos($path, '/files/');
            if ($pos !== false) {
                $path = substr($path, $pos + 7);
            }
        } catch (\Throwable $e) {
        }
        $token = $share->getToken() ?? '';
        $url = '';
        if ($token !== '' && $share->getShareType() === IShare::TYPE_LINK &&
            class_exists(\OCP\Server::class)) {
            try {
                $url = \OCP\Server::get(\OCP\IURLGenerator::class)->linkToRouteAbsolute('files_sharing.sharecontroller.showShare', ['token' => $token]);
            } catch (\Throwable $e) {
            }
        }
        $note = '';
        if (method_exists($share, 'getNote')) {
            try {
                $note = (string)$share->getNote();
            } catch (\Throwable $e) {
            }
        }
        $exp = null;
        try {
            $exp = $share->getExpirationDate()?->format('Y-m-d');
        } catch (\Throwable $e) {
        }
        return [
            'id' => (string)$share->getId(),
            'direction' => $direction,
            'type' => $this->typeName($share->getShareType()),
            'path' => $path,
            'recipient' => (string)($share->getSharedWith() ?? ''),
            'token' => $token,
            'url' => $url,
            'expiration' => $exp,
            'note' => $note,
            'permissions' => (int)$share->getPermissions(),
            'created' => $share->getShareTime()?->format('Y-m-d') ?? '',
        ];
    }

    private function typeName(int $type): string {
        return match ($type) {
            IShare::TYPE_USER => 'user',
            IShare::TYPE_GROUP => 'group',
            IShare::TYPE_LINK => 'link',
            IShare::TYPE_EMAIL => 'email',
            IShare::TYPE_CIRCLE => 'circle',
            IShare::TYPE_ROOM => 'room',
            default => (string)$type,
        };
    }

    /** Resolve share id ("ocinternal:12", "ocRoomShare:3" or plain id) owned by the user. */
    private function findOwnShare(string $userId, string $id): ?IShare {
        foreach ([IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_LINK] as $type) {
            try {
                $shares = $this->shareManager->getSharesBy($userId, $type, null, true, 500, 0);
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($shares as $share) {
                if ((string)$share->getId() === $id
                    || (str_starts_with((string)$share->getFullId(), 'oc:') && substr((string)$share->getFullId(), 3) === $id)) {
                    return $share;
                }
            }
        }
        return null;
    }
}
