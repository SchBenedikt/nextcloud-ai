<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Dto\DocumentsQuery;
use OCA\EvaAi\Dto\DocumentChunksQuery;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\RagService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** Document listing and chunk retrieval endpoints. */
final class DocumentsController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private AppConfig $config,
		private DocumentMapper $documentMapper,
		private RagService $ragService,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
		$this->config->setUserId($this->userId);
	}

	private function requireUser(): ?string {
		return $this->userId !== null && $this->userId !== '' ? $this->userId : null;
	}

	#[NoAdminRequired]
	public function documents(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) {
			return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		}
		try {
			$query = DocumentsQuery::fromArray([
				'search' => $this->requestParam('search'),
				'limit' => $this->requestParam('limit'),
				'offset' => $this->requestParam('offset'),
				'type' => $this->requestParam('type'),
				'folder' => $this->requestParam('folder'),
				'dateFrom' => $this->requestParam('dateFrom'),
				'dateTo' => $this->requestParam('dateTo'),
				'sizeMin' => $this->requestParam('sizeMin'),
				'sizeMax' => $this->requestParam('sizeMax'),
				'sort' => $this->requestParam('sort'),
				'dir' => $this->requestParam('dir'),
			]);
		} catch (\InvalidArgumentException $e) {
			return new ErrorDataResponse(['error' => $e->getMessage()], 400);
		}
		$docs = $this->documentMapper->findByUser($user, $query->search, $query->limit, $query->offset, $query->filters, $query->sort, $query->direction);
		$out = array_map(static function ($d) {
			return [
				'id' => (int)$d->getId(),
				'path' => $d->getPath(),
				'name' => $d->getName(),
				'mime' => $d->getMime(),
				'size' => (int)$d->getSize(),
				'chunks' => (int)$d->getChunkCount(),
				'indexedAt' => $d->getIndexedAt(),
			];
		}, $docs);
		$aggregates = $this->documentMapper->aggregateForUser($user, $query->search, $query->filters);
		$payload = [
			'documents' => $out,
			'total' => $aggregates['count'],
			'totalChunks' => $aggregates['chunks'],
			'totalSize' => $aggregates['size'],
		];
		$etag = '"' . hash('sha256', (string)json_encode($payload, JSON_UNESCAPED_SLASHES)) . '"';
		$headers = ['Cache-Control' => 'private, max-age=300', 'ETag' => $etag];
		$ifNoneMatch = trim((string)$this->request->getHeader('If-None-Match'));
		if ($ifNoneMatch !== '' && hash_equals($etag, $ifNoneMatch)) {
			return new ErrorDataResponse([], 304, $headers);
		}
		return new ErrorDataResponse($payload, 200, $headers);
	}

	#[NoAdminRequired]
	public function documentChunks(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) {
			return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		}
		try {
			$query = DocumentChunksQuery::fromArray([
				'documentId' => $this->requestParam('documentId'),
				'limit' => $this->requestParam('limit'),
				'offset' => $this->requestParam('offset'),
			]);
		} catch (\InvalidArgumentException $e) {
			return new ErrorDataResponse(['error' => $e->getMessage()], 400);
		}
		if ($query->documentId === null) {
			return new ErrorDataResponse(['error' => 'documentId is required'], 400);
		}
		$chunks = $this->documentMapper->chunksForDocument($user, $query->documentId, $query->limit, $query->offset);
		$payload = [
			'chunks' => array_map(static function ($c) {
				return [
					'id' => (int)$c['id'],
					'content' => $c['content'],
					'start' => (int)$c['start'],
					'end' => (int)$c['end'],
				];
			}, $chunks),
			'total' => $this->documentMapper->countChunksForDocument($user, $query->documentId),
		];
		return new ErrorDataResponse($payload);
	}
}