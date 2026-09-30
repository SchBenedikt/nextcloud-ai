<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Dto\ImageGenerationRequest;
use OCA\EvaAi\Dto\GeneratedImageListResponse;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Service\ImageGenerationService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\OCSController;
use OCP\ICacheFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/** User-scoped image generation and gallery endpoints. */
final class ImageController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private ImageGenerationService $images,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function listImages(): DataResponse {
		if ($this->userId === null || $this->userId === '') return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		try {
			$limit = (int)$this->request->getParam('limit', 24);
			return new ErrorDataResponse(GeneratedImageListResponse::fromArray(
				$this->images->listForUser($this->userId, $limit),
			)->toArray());
		} catch (Throwable $e) {
			$this->logger->warning('eva_ai: generated image gallery unavailable', ['exception' => $e]);
			return new ErrorDataResponse(['error' => 'Could not load generated images.'], 500);
		}
	}

	#[NoAdminRequired]
	public function download(string $id): DataResponse|DataDownloadResponse {
		if ($this->userId === null || $this->userId === '') return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		if (!ctype_digit($id) || (int)$id < 1) return new ErrorDataResponse(['error' => 'Generated image not found.'], 404);
		try {
			$file = $this->images->findGeneratedFile($this->userId, (int)$id);
			if ($file === null) return new ErrorDataResponse(['error' => 'Generated image not found.'], 404);
			return new DataDownloadResponse($file->getContent(), $file->getName(), $file->getMimeType());
		} catch (Throwable $e) {
			$this->logger->warning('eva_ai: generated image download failed', ['user' => $this->userId, 'exception' => $e]);
			return new ErrorDataResponse(['error' => 'Could not download this generated image.'], 500);
		}
	}

	#[NoAdminRequired]
	public function generate(): DataResponse {
		if ($this->userId === null || $this->userId === '') return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		$body = $this->jsonBody();
		try {
			$input = ImageGenerationRequest::fromArray([
				'prompt' => $body['prompt'] ?? $this->request->getParam('prompt'),
				'count' => $body['count'] ?? $this->request->getParam('count', 1),
			]);
		} catch (\InvalidArgumentException $e) {
			return new ErrorDataResponse(['error' => $e->getMessage()], 400);
		}
		if ($this->rateLimited($this->userId)) {
			return new ErrorDataResponse(['error' => 'rate_limited', 'message' => 'Image generation limit reached. Try again in a few minutes.'], 429, ['Retry-After' => '900']);
		}
		try {
			return new ErrorDataResponse(GeneratedImageListResponse::fromArray(
				$this->images->generate($this->userId, $input->prompt, $input->count),
			)->toArray(), 201);
		} catch (Throwable $e) {
			$this->logger->warning('eva_ai: image generation failed', ['user' => $this->userId, 'exception' => $e]);
			return new ErrorDataResponse([
				'error' => 'Image generation failed. Check the configured provider and try again.',
			], 502);
		}
	}

	/** @return array<string,mixed> */
	private function jsonBody(): array {
		$stream = fopen('php://input', 'rb');
		$raw = $stream !== false ? stream_get_contents($stream, 65537) : false;
		if (is_resource($stream)) fclose($stream);
		if (!is_string($raw) || strlen($raw) > 65536) return [];
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}

	private function rateLimited(string $userId): bool {
		try {
			$cache = $this->cacheFactory->createLocking('eva_ai_rate');
			$window = intdiv(time(), 900);
			$key = 'image:v1:' . hash('sha256', $userId) . ':' . $window;
			$cache->add($key, 0, 901);
			return $cache->inc($key) > 5;
		} catch (Throwable $e) {
			$this->logger->debug('eva_ai: image generation limiter unavailable', ['exception' => $e]);
			return false;
		}
	}
}
