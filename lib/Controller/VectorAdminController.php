<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Dto\VectorConnectionRequest;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Vector\QdrantConnection;
use OCA\EvaAi\Vector\QdrantVectorStore;
use OCA\EvaAi\Vector\VectorStoreException;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/** Bounded administrator operations, separate from the general admin controller. */
class VectorAdminController extends OCSController {
    public function __construct(string $appName, IRequest $request, private QdrantConnection $connection, private QdrantVectorStore $store) {
        parent::__construct($appName, $request);
    }

    #[AdminRequired]
    public function connection(): DataResponse {
        try { return new ErrorDataResponse($this->connection->configuration()); }
        catch (\Throwable) { return $this->failure('configuration_unavailable', 'Storage configuration could not be loaded.', 503); }
    }

    /** Explicit full replacement; no HTTP request or generation activation. */
    #[AdminRequired]
    public function configure(): DataResponse {
        try {
            // Credentials belong in the JSON body, never URL/query parameters.
            if (!preg_match('~^application/json(?:\s*;|$)~i', $this->request->getHeader('Content-Type'))) {
                return $this->failure('invalid_request', 'Send storage configuration as JSON.', 400);
            }
            $raw = file_get_contents('php://input', false, null, 0, 8193);
            if (!is_string($raw) || strlen($raw) > 8192) throw new \InvalidArgumentException('Storage configuration is oversized.');
            $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($input) || array_is_list($input)) throw new \InvalidArgumentException('Send a configuration object.');
            $update = VectorConnectionRequest::fromArray($input);
            $this->connection->configure($update->endpoint, $update->apiKey, $update->noKey, $update->enabled);
            return new ErrorDataResponse($this->connection->configuration());
        } catch (\InvalidArgumentException | \JsonException) {
            return $this->failure('invalid_request', 'Enter a valid server URL, authentication choice and API key. Supply enabled as a JSON boolean.', 400);
        } catch (VectorStoreException $e) {
            $message = match ($e->reason) {
                'remote_tls_required' => 'Remote storage requires HTTPS. HTTP is allowed only for loopback servers.',
                'invalid_endpoint' => 'Enter a server URL without credentials, query parameters or a fragment.',
                default => 'Storage configuration is invalid.',
            };
            return $this->failure('invalid_request', $message, 400);
        } catch (\Throwable) { return $this->failure('configuration_failed', 'Storage configuration could not be saved. Reload its current state before retrying.', 503); }
    }

    #[AdminRequired]
    public function disable(): DataResponse {
        try { $this->connection->disable(); return new ErrorDataResponse($this->connection->status()); }
        catch (\Throwable) { return $this->failure('configuration_failed', 'Storage requests could not be disabled. Reload the current state before retrying.', 503); }
    }

    /** Explicit POST: root API probe only, no collection preparation or content. */
    #[AdminRequired]
    public function check(): DataResponse {
        try { return new ErrorDataResponse($this->store->health()); }
        catch (\Throwable) { return $this->failure('check_failed', 'Storage connection could not be checked.', 503); }
    }

    private function failure(string $code, string $message, int $status): DataResponse {
        return new ErrorDataResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
