<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Controller\Traits\ChatRequestCoordination;
use OCA\EvaAi\Controller\Traits\ReadsRequestParameters;
use OCA\EvaAi\Controller\Traits\RequestLimits;
use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Dto\FileContextChatRequest;
use OCA\EvaAi\Dto\FileContextStatusResponse;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\FileContextChatService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

/** Chat and status for explicitly selected Nextcloud files. */
final class FileContextController extends OCSController {
	use ChatRequestCoordination;
	use ReadsRequestParameters;
	use RequestLimits;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private AppConfig $config,
		private FileContextChatService $fileContextChat,
		private DocumentMapper $documentMapper,
		private ILockingProvider $lockingProvider,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
		private ?\OCA\EvaAi\Service\RequestRateLimiter $requestRateLimiter = null,
	) {
		parent::__construct($appName, $request);
		$this->config->setUserId($this->userId);
	}

	#[NoAdminRequired]
	public function fileContextChat(): DataResponse {
		$user = $this->userId ?: null;
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		try {
			$request = FileContextChatRequest::fromArray([
				'fileIds' => $this->requestParam('fileIds'),
				'message' => $this->requestParam('message'),
				'history' => $this->requestParam('history'),
			]);
		} catch (\InvalidArgumentException $e) {
			return new ErrorDataResponse(['error' => $e->getMessage()], 400);
		}
		if (($limited = $this->consumeRequestLimit($user, 'file_context', $this->config->getInt('rate_limit_chat_per_minute', 30), $this->requestRateLimiter, $this->cacheFactory, $this->logger)) !== null) return $limited;
		try { $chatSlot = $this->acquireChatRequestSlot($user, $this->lockingProvider, $this->logger); }
		catch (\Throwable) { return new ErrorDataResponse(['error' => 'chat_unavailable', 'message' => 'Chat is temporarily unavailable. Please retry shortly.'], 503); }
		if ($chatSlot === null) return new ErrorDataResponse(['error' => 'busy', 'message' => 'Another chat request is already running. Please retry shortly.'], 429, ['Retry-After' => '5']);
		try {
			$this->releasePhpSessionLock();
			return new ErrorDataResponse($this->fileContextChat->chat($user, $request->fileIds, $request->message, $request->history));
		} finally {
			$this->releaseChatRequestSlot($chatSlot, $this->lockingProvider, $this->logger);
		}
	}

	#[NoAdminRequired]
	public function fileContextStatus(): DataResponse {
		$user = $this->userId ?: null;
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		$fileIds = $this->requestParam('fileIds');
		if (!is_array($fileIds)) $fileIds = [];
		$fileIds = array_values(array_filter(array_map('intval', $fileIds), static fn ($id): bool => $id > 0));
		if ($fileIds === []) return new ErrorDataResponse(FileContextStatusResponse::forRequestedFiles([], [])->toArray());
		$documents = $this->fileContextChat->accessibleDocuments($user, $this->documentMapper->findByUserAndFileIds($user, $fileIds));
		$files = [];
		foreach ($documents as $document) {
			$files[] = ['fileId' => (int)$document->getFileId(), 'name' => $document->getName(), 'path' => $document->getPath()];
		}
		return new ErrorDataResponse(FileContextStatusResponse::forRequestedFiles($fileIds, $files)->toArray());
	}
}
