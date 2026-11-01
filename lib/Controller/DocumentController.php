<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Controller\Traits\ReadsRequestParameters;
use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Dto\DocumentChunksQuery;
use OCA\EvaAi\Dto\DocumentsQuery;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Service\FileContextChatService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/** User-scoped inspection of the canonical SQL document index. */
final class DocumentController extends OCSController {
	use ReadsRequestParameters;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private DocumentMapper $documents,
		private ChunkMapper $chunks,
		private FileContextChatService $fileAccess,
	) {
		parent::__construct($appName, $request);
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

		$rows = $this->documents->findByUser(
			$user,
			$query->search,
			$query->limit,
			$query->offset,
			$query->filters,
			$query->sort,
			$query->direction,
		);
		$items = array_map(static fn($document): array => [
			'id' => (int)$document->getId(),
			'path' => $document->getPath(),
			'name' => $document->getName(),
			'mime' => $document->getMime(),
			'size' => (int)$document->getSize(),
			'chunks' => (int)$document->getChunkCount(),
			'indexedAt' => $document->getIndexedAt(),
		], $rows);

		// Totals describe the full filtered index, independent of the page.
		$aggregates = $this->documents->aggregateForUser($user, $query->search, $query->filters);
		$payload = [
			'documents' => $items,
			'total' => $aggregates['count'],
			'totalChunks' => $aggregates['chunks'],
			'totalSize' => $aggregates['size'],
		];
		$etag = '"' . hash('sha256', (string)json_encode($payload, JSON_UNESCAPED_SLASHES)) . '"';
		$headers = ['Cache-Control' => 'private, no-cache', 'ETag' => $etag];
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
				'id' => $this->requestParam('id'),
				'limit' => $this->requestParam('limit'),
				'offset' => $this->requestParam('offset'),
			]);
		} catch (\InvalidArgumentException $e) {
			return new ErrorDataResponse(['error' => $e->getMessage()], 400);
		}

		$id = $query->id;
		$document = $id > 0 ? $this->documents->findById($id) : null;
		if ($document === null || $document->getUserId() !== $user
			|| !$this->fileAccess->fileAccessible($user, (int)$document->getFileId())) {
			return new ErrorDataResponse(['error' => 'Document not found'], 404);
		}

		// Bound the response size; the client requests further pages as needed.
		$rows = $this->chunks->findByDocument($id, $query->limit, $query->offset);
		$totalChunks = (int)$document->getChunkCount();
		$nextOffset = $query->offset + count($rows);
		return new ErrorDataResponse([
			'document' => [
				'id' => (int)$document->getId(),
				'path' => $document->getPath(),
				'chunks' => $totalChunks,
			],
			'offset' => $query->offset,
			'limit' => $query->limit,
			'hasMore' => $nextOffset < $totalChunks,
			'nextOffset' => $nextOffset,
			'chunks' => array_map(static fn(array $chunk): array => [
				'index' => (int)$chunk['chunk_index'],
				'content' => (string)$chunk['content'],
				'provenance' => json_decode((string)($chunk['provenance'] ?? '{}'), true) ?: [],
			], $rows),
		], 200, ['Cache-Control' => 'private, max-age=300']);
	}

	private function requireUser(): ?string {
		return $this->userId !== null && $this->userId !== '' ? $this->userId : null;
	}

}
