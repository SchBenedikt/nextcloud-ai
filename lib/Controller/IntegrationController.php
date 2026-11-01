<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Controller\Traits\ReadsRequestParameters;
use OCA\EvaAi\Dto\PluginToggleRequest;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\PluginToolSettings;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/** User-facing API for configured Nextcloud and third-party integrations. */
final class IntegrationController extends OCSController {
    use ReadsRequestParameters;

    public function __construct(
        string $appName,
        IRequest $request,
        private ?string $userId,
        private AppConfig $config,
        private ActionExecutor $executor,
    ) {
        parent::__construct($appName, $request);
    }

    private function requireUser(): ?string {
        return $this->userId !== null && $this->userId !== '' ? $this->userId : null;
    }

    #[NoAdminRequired]
    public function externalConnectors(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        return new ErrorDataResponse($this->executor->run($user, 'list_external_connectors', []));
    }

    /** List installed third-party EVA tools without exposing plugin secrets. */
    #[NoAdminRequired]
    public function plugins(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        // The plugin catalog is optional UI metadata. A third-party plugin
        // must never make the EVA app page fail while the built-in tools and
        // chat remain available. Registration already isolates plugin events;
        // keep the same failure boundary around catalog/schema generation.
        try {
            $plugins = array_values(array_filter(
                $this->executor->pluginCatalog(),
                static fn(array $tool): bool => str_starts_with((string)($tool['name'] ?? ''), 'plugin_')
            ));
        } catch (\Throwable) {
            $plugins = [];
        }
        return new ErrorDataResponse(['plugins' => $plugins]);
    }

    #[NoAdminRequired]
    public function setPluginEnabled(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        $raw = file_get_contents('php://input', false, null, 0, 4097);
        if (!is_string($raw) || strlen($raw) > 4096) return new ErrorDataResponse(['error' => 'Plugin settings request is too large.'], 413);
        $decoded = json_decode($raw, true);
        try {
            $input = PluginToggleRequest::fromArray(is_array($decoded) ? $decoded : []);
        } catch (\InvalidArgumentException $e) {
            return new ErrorDataResponse(['error' => $e->getMessage()], 400);
        }
        $this->config->setUserId($user);
        $this->executor->setUserId($user);
        $known = [];
        try {
            foreach ($this->executor->pluginCatalog() as $plugin) {
                $name = (string)($plugin['name'] ?? '');
                if ($name !== '') $known[$name] = true;
            }
        } catch (\Throwable) {
            return new ErrorDataResponse(['error' => 'The EVA extension catalog is unavailable.'], 503);
        }
        if (!isset($known[$input->name])) return new ErrorDataResponse(['error' => 'Plugin tool not found.'], 404);

        $this->config->set('plugin_tools_enabled', PluginToolSettings::withEnabledState(
            $this->config->get('plugin_tools_enabled'),
            $input->name,
            $input->enabled,
            array_keys($known),
        ));
        return new ErrorDataResponse(['name' => $input->name, 'enabled' => $input->enabled]);
    }

    #[NoAdminRequired]
    public function saveExternalConnector(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        $body = $this->requestBody();
        $result = $this->executor->runConfirmed($user, 'configure_external_connector', $body);
        return new ErrorDataResponse($result, ($result['ok'] ?? false) ? 200 : 400);
    }

    #[NoAdminRequired]
    public function discoverExternalConnector(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        $result = $this->executor->run($user, 'discover_external_connector', $this->requestBody());
        return new ErrorDataResponse($result, ($result['ok'] ?? false) ? 200 : 400);
    }

    /** Read-only transport diagnostic executed on the Nextcloud host. */
    #[NoAdminRequired]
    public function diagnoseExternalConnector(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        $result = $this->executor->run($user, 'diagnose_external_connector', $this->requestBody());
        return new ErrorDataResponse($result, ($result['ok'] ?? false) ? 200 : 400);
    }

    /** Perform an explicit, read-only connectivity check against a connector. */
    #[NoAdminRequired]
    public function testExternalConnector(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        $id = strtolower(trim((string)($this->requestBody()['id'] ?? $this->requestParam('id') ?? '')));
        if ($id === '') return new ErrorDataResponse(['error' => 'Connector id required'], 400);
        // A test must diagnose transport and authentication, not guess a
        // random learned route (which may be protected or require path
        // parameters). The diagnostic uses the same credentials and host
        // validation as normal calls and never returns response data.
        $result = $this->executor->run($user, 'diagnose_external_connector', ['id' => $id]);
        $httpStatus = (($result['ok'] ?? false) || isset($result['result'])) ? 200 : 400;
        return new ErrorDataResponse($result, $httpStatus);
    }

    #[NoAdminRequired]
    public function deleteExternalConnector(): DataResponse {
        $user = $this->requireUser();
        if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
        $id = strtolower(trim((string)($this->requestParam('id') ?? $this->requestBody()['id'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D', $id)) return new ErrorDataResponse(['error' => 'Valid connector id required'], 400);
        $config = \OCP\Server::get(\OCP\IConfig::class);
        $raw = json_decode($config->getUserValue($user, AppConfig::APP, 'external_connectors', '{}'), true);
        if (!is_array($raw) || !array_key_exists($id, $raw)) return new ErrorDataResponse(['error' => 'Connector not found'], 404);
        unset($raw[$id]); $config->setUserValue($user, AppConfig::APP, 'external_connectors', json_encode($raw, JSON_UNESCAPED_SLASHES) ?: '{}');
        \OCP\Server::get(\OCA\EvaAi\Service\ProviderCredentials::class)->saveCustom($user, 'connector_' . $id, '');
        return new ErrorDataResponse(['ok' => true, 'deleted' => $id]);
    }
}
