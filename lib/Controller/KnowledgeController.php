<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Controller\Traits\ReadsRequestParameters;
use OCA\EvaAi\Dto\KnowledgeContentRequest;
use OCA\EvaAi\Dto\KnowledgeContentResponse;
use OCA\EvaAi\Dto\KnowledgeContentSaveResponse;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Service\KnowledgeInitializer;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** Reads and saves the current user's personal EVA knowledge file via VFS. */
final class KnowledgeController extends OCSController {
    use ReadsRequestParameters;

    public function __construct(
        string $appName,
        IRequest $request,
        private ?string $userId,
        private IRootFolder $rootFolder,
        private KnowledgeInitializer $knowledgeInitializer,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    private function requireUser(): ?string {
        return $this->userId !== null && $this->userId !== '' ? $this->userId : null;
    }

    /** Read this account's initialized, VFS-backed knowledge file. */
    #[NoAdminRequired]
    public function knowledge(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $this->knowledgeInitializer->ensureInitialized($user);
            $home = $this->rootFolder->getUserFolder($user);
            $content = '';
            if ($home->nodeExists('KNOWLEDGE.md')) {
                $node = $home->get('KNOWLEDGE.md');
                if ($node instanceof \OCP\Files\File) {
                    $content = (string)$node->getContent();
                }
            }
            return new ErrorDataResponse(KnowledgeContentResponse::fromContent($content)->toArray());
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: personal knowledge file read failed', ['user' => $user, 'exception' => $e]);
            return new ErrorDataResponse(['error' => 'Could not read personal knowledge file.'], 500);
        }
    }

    #[NoAdminRequired]
    public function saveKnowledge(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) {
            return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        }
        try {
            $request = KnowledgeContentRequest::fromArray(['content' => $this->requestParam('content', '')]);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        $content = $request->content;
        try {
            $home = $this->rootFolder->getUserFolder($user);
            $path = 'KNOWLEDGE.md';
            if ($home->nodeExists($path)) {
                $home->get($path)->putContent($content);
            } else {
                $home->newFile($path, $content);
            }
            return new ErrorDataResponse(KnowledgeContentSaveResponse::saved($content)->toArray());
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: personal knowledge file save failed', ['user' => $user, 'exception' => $e]);
            return new ErrorDataResponse(['error' => 'Could not save personal knowledge file.'], 500);
        }
    }

}
