<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Server;

/** Discovers and executes confirmation-gated same-origin Nextcloud app APIs. */
final class AppApiToolExecutor implements DomainToolExecutor {
    private const LEARNED_API_TTL = 2592000;
    private const APP_API_TIMEOUT = 30;
    private const APP_API_BATCH_BUDGET = 20;
    private ?array $learnedApisCache = null;

    public function __construct(private AppConfig $config, private ExternalToolExecutor $externalExecutor) {
    }

    public function tools(): array {
        return ['list_nextcloud_capabilities', 'discover_app_api', 'list_learned_app_apis', 'call_app_api', 'call_app_api_batch'];
    }

    public function execute(string $tool, string $userId, array $args): array {
        return match ($tool) {
            'list_nextcloud_capabilities' => $this->listNextcloudCapabilities(),
            'discover_app_api' => $this->discoverAppApi($args),
            'list_learned_app_apis' => $this->listLearnedAppApis(),
            'call_app_api' => $this->callAppApi($args),
            'call_app_api_batch' => $this->callAppApiBatch($args),
            default => ['ok' => false, 'error' => 'Unsupported Nextcloud app API tool: ' . $tool],
        };
    }

    /** Read-only capability discovery for agent planning; never returns secrets. */
    private function listNextcloudCapabilities(): array {
        try {
            $manager = Server::get(\OCP\App\IAppManager::class);
            $apps = array_values(array_unique(array_map('strval', $manager->getEnabledApps())));
            sort($apps, SORT_STRING);
            $apiCatalog = [
                'files' => ['protocols' => ['WebDAV', 'OCS'], 'eva_tools' => ['list_files', 'read_file', 'search_files', 'create_file', 'rename_file', 'delete_file']],
                'calendar' => ['protocols' => ['CalDAV', 'OCS'], 'eva_tools' => ['list_calendars', 'list_calendar_events', 'find_free_slots', 'create_calendar_event', 'update_calendar_event', 'delete_calendar_event']],
                'contacts' => ['protocols' => ['CardDAV', 'OCS'], 'eva_tools' => ['list_contacts', 'find_contact', 'create_contact', 'update_contact', 'delete_contact']],
                'mail' => ['protocols' => ['IMAP/Nextcloud Mail service'], 'eva_tools' => ['search_mails', 'list_mails', 'read_mail', 'unread_mail_count']],
                'spreed' => ['protocols' => ['OCS Talk API'], 'eva_tools' => ['list_talk_rooms', 'read_talk_chat', 'send_talk_message']],
                'notes' => ['protocols' => ['Nextcloud Notes service/WebDAV'], 'eva_tools' => ['create_note', 'read_file', 'search_files']],
                'activity' => ['protocols' => ['OCS Activity API'], 'eva_tools' => ['recent_activity']],
                'files_sharing' => ['protocols' => ['OCS Sharing API'], 'eva_tools' => ['list_shares', 'create_share', 'update_share', 'delete_share']],
                'deck' => ['protocols' => ['Deck OCS API'], 'eva_tools' => [], 'status' => 'discovery only; no dedicated EVA adapter installed'],
                'bookmarks' => ['protocols' => ['Bookmarks REST API'], 'eva_tools' => [], 'status' => 'discovery only; no dedicated EVA adapter installed'],
                'forms' => ['protocols' => ['Forms OCS API'], 'eva_tools' => [], 'status' => 'discovery only; no dedicated EVA adapter installed'],
                'comments' => ['protocols' => ['OCS Comments API', 'server-side ICommentsManager'], 'eva_tools' => ['list_comments', 'add_comment', 'delete_comment']],
                'systemtags' => ['protocols' => ['server-side ISystemTagManager/ISystemTagObjectMapper', 'OCS Files Tags API'], 'eva_tools' => ['list_system_tags', 'tag_file', 'untag_file']],
                'files_versions' => ['protocols' => ['server-side IVersionManager'], 'eva_tools' => ['list_file_versions', 'restore_file_version']],
                '_generic' => ['protocols' => ['Nextcloud route metadata, OCS and app-specific routes'], 'eva_tools' => ['discover_app_api', 'call_app_api'], 'status' => 'unknown app routes can be learned and invoked through the confirmation-gated generic adapter'],
            ];
            $availableApis = [];
            foreach ($apiCatalog as $app => $metadata) if (in_array($app, $apps, true)) $availableApis[$app] = $metadata;
            $availableApis['_generic'] = $apiCatalog['_generic'];
            $appMetadata = [];
            try {
                $manager = Server::get(\OCP\App\IAppManager::class);
                foreach ($apps as $app) {
                    $info = method_exists($manager, 'getAppInfo') ? $manager->getAppInfo($app) : [];
                    if (!is_array($info)) $info = [];
                    $appMetadata[$app] = [
                        'name' => (string)($info['name'] ?? $app),
                        'version' => (string)($info['version'] ?? ''),
                        'description' => mb_strimwidth((string)($info['description'] ?? ''), 0, 240, '…'),
                    ];
                }
            } catch (\Throwable) { /* metadata is optional on older NC versions */ }
            return [
                'ok' => true,
                'enabled_apps' => $apps,
                'app_metadata' => $appMetadata,
                'eva_integrations' => [
                    'files' => in_array('files', $apps, true),
                    'calendar' => in_array('calendar', $apps, true),
                    'contacts' => in_array('contacts', $apps, true),
                    'mail' => in_array('mail', $apps, true),
                    'spreed' => in_array('spreed', $apps, true),
                    'notes' => in_array('notes', $apps, true),
                ],
                'api_catalog' => $availableApis,
                'next_step' => 'Plan with the protocols and EVA tools listed above. Prefer a dedicated EVA adapter; for an enabled app without one, call list_learned_app_apis or discover_app_api first (include_internal=true when needed), then use the exact same-origin discovered route with call_app_api. Generic calls are always confirmation-gated interactively and require the encrypted app token in background runs.',
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Nextcloud capability discovery is unavailable.'];
        }
    }

    /**
     * Read route metadata from Nextcloud's router without invoking controllers.
     * This gives the agent a safe way to learn an installed app's API surface;
     * execution still goes through a same-origin app path and its normal
     * Nextcloud authentication/permission checks.
     */
    private function discoverAppApi(array $args): array {
        $appId = strtolower(trim((string)($args['app_id'] ?? '')));
        if ($appId !== '' && !preg_match('/^[a-z0-9_]+$/', $appId)) {
            return ['ok' => false, 'error' => 'app_id must contain only lowercase letters, numbers and underscores.'];
        }
        try {
            $appManager = Server::get(\OCP\App\IAppManager::class);
            $enabled = array_values(array_unique(array_map('strval', $appManager->getEnabledApps())));
            if ($appId !== '' && !in_array($appId, $enabled, true)) {
                return ['ok' => false, 'error' => 'That app is not enabled or is not available to this instance.'];
            }
            $router = Server::get(\OCP\Route\IRouter::class);
            if (method_exists($router, 'loadRoutes')) $router->loadRoutes($appId !== '' ? $appId : null);
            if (!method_exists($router, 'getRouteCollection')) {
                return ['ok' => false, 'error' => 'This Nextcloud version does not expose route discovery.'];
            }
            $collection = $router->getRouteCollection();
            $includeInternal = (bool)($args['include_internal'] ?? false);
            $routes = [];
            foreach ($collection->all() as $name => $route) {
                $defaults = $route->getDefaults();
                $controller = (string)($defaults['_controller'] ?? '');
                $routeApp = strtolower((string)($defaults['_app'] ?? ''));
                if ($routeApp === '' && is_string($name) && str_contains($name, '#')) {
                    $routeApp = strtolower((string)strtok($name, '#'));
                }
                if ($appId !== '' && $routeApp !== $appId && !str_starts_with(strtolower((string)$name), $appId . '.')) continue;
                $path = method_exists($route, 'getPath') ? (string)$route->getPath() : '';
                $isOcs = str_starts_with($path, '/ocs/') || str_starts_with($path, '/ocsapp/');
                if (!$includeInternal && !$isOcs) continue;
                $methods = method_exists($route, 'getMethods') ? array_values(array_map('strval', $route->getMethods())) : [];
                $variables = method_exists($route, 'getVariableNames') ? array_values(array_map('strval', $route->getVariableNames())) : [];
                $requirements = method_exists($route, 'getRequirements') ? array_map('strval', $route->getRequirements()) : [];
                $routes[] = [
                    'name' => (string)$name,
                    'app_id' => $routeApp,
                    'methods' => $methods,
                    'path' => $path,
                    'controller' => $controller,
                    'ocs' => $isOcs,
                    'variables' => $variables,
                    'requirements' => $requirements,
                ];
            }
            usort($routes, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            if (count($routes) > 300) $routes = array_slice($routes, 0, 300);
            $this->rememberAppApi($appId, $routes);
            return ['ok' => true, 'result' => ['app_id' => $appId !== '' ? $appId : null, 'route_count' => count($routes), 'routes' => $routes, 'execution_policy' => 'Discovery never executes a route. Use the exact same-origin path with call_app_api; route variables and requirements describe the path placeholders. Non-OCS routes must be discovered with include_internal=true. Interactive calls require confirmation and background calls require the encrypted app token.']];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Nextcloud app API discovery is unavailable.'];
        }
    }

    private function getLearnedApis(): array {
        if ($this->learnedApisCache !== null) {
            return $this->learnedApisCache;
        }
        $raw = $this->config->get('learned_app_apis');
        $decoded = json_decode($raw, true);
        $this->learnedApisCache = is_array($decoded) ? $decoded : [];
        return $this->learnedApisCache;
    }

    private function rememberAppApi(string $appId, array $routes): void {
        if ($appId === '') return;
        try {
            $known = $this->getLearnedApis();
            $sanitized = [];
            foreach (array_slice($routes, 0, 300) as $route) {
                if (!is_array($route)) continue;
                $sanitized[] = [
                    'name' => (string)($route['name'] ?? ''),
                    'methods' => array_values(array_map('strval', is_array($route['methods'] ?? null) ? $route['methods'] : [])),
                    'path' => (string)($route['path'] ?? ''),
                    'ocs' => (bool)($route['ocs'] ?? false),
                    'variables' => array_values(array_map('strval', is_array($route['variables'] ?? null) ? $route['variables'] : [])),
                    'requirements' => array_map('strval', is_array($route['requirements'] ?? null) ? $route['requirements'] : []),
                ];
            }
            $known[$appId] = ['updated' => time(), 'routes' => $sanitized];
            if (count($known) > 30) {
                uasort($known, static fn (array $a, array $b): int => ((int)($b['updated'] ?? 0)) <=> ((int)($a['updated'] ?? 0)));
                $known = array_slice($known, 0, 30, true);
            }
            $this->config->set('learned_app_apis', json_encode($known, JSON_UNESCAPED_SLASHES) ?: '{}');
            $this->learnedApisCache = $known;
        } catch (\Throwable) { /* Learning is best effort. */ }
    }

    private function listLearnedAppApis(): array {
        try {
            $known = $this->getLearnedApis();
            return ['ok' => true, 'result' => ['apps' => $known, 'note' => 'Route metadata is cached per user and may be stale; refresh with discover_app_api before acting.']];
        } catch (\Throwable) { return ['ok' => true, 'result' => ['apps' => []]]; }
    }

    private function callAppApi(array $args): array {
        $appId = strtolower(trim((string)($args['app_id'] ?? '')));
        $method = strtoupper(trim((string)($args['method'] ?? '')));
        $path = trim((string)($args['path'] ?? ''));
        $params = $args['params'] ?? [];
        // Models sometimes classify an explicitly connected appliance as an
        // "app" because its API is app-shaped. Route that alias through the
        // connector implementation so host allow-listing, discovered-route
        // checks, bearer-token handling and GET retries remain enforced.
        if ($appId !== '' && $this->externalExecutor->hasConnector($appId)) {
            return $this->externalExecutor->execute('call_external_connector', $this->config->userId() ?? '', ['id' => $appId, 'method' => $method, 'path' => $path, 'params' => $params]);
        }
        if (!preg_match('/^[a-z0-9_]+$/', $appId) || !in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return ['ok' => false, 'error' => 'A valid app_id and HTTP method are required.'];
        }
        if (!is_array($params) || count($params) > 50) return ['ok' => false, 'error' => 'params must be an object with at most 50 fields.'];
        $split = $this->splitRequestPath($path, $params);
        if ($split === null) return ['ok' => false, 'error' => 'The app API path or query string is invalid.'];
        [$path, $params] = $split;
        $ocsPrefixes = ['/ocs/v1.php/apps/' . $appId . '/', '/ocs/v2.php/apps/' . $appId . '/'];
        $isOcsPath = false;
        foreach ($ocsPrefixes as $prefix) if (str_starts_with($path, $prefix)) $isOcsPath = true;
        if (str_contains($path, '..') || preg_match('/[\r\n]/', $path) || !str_starts_with($path, '/')) {
            return ['ok' => false, 'error' => 'Only same-origin app paths without traversal are allowed.'];
        }
        // Nextcloud app routes are exposed externally below
        // /apps/{app_id}/..., while models naturally return the app-relative
        // route (/api/v1/people). Match and execute both forms consistently.
        $absolutePath = $path;
        if (!$isOcsPath && str_starts_with($path, '/api/')) {
            $absolutePath = '/apps/' . $appId . $path;
        }
        // Every generic route must be present in the user's recent discovery
        // snapshot.  Prefix-only checks are not sufficient: an enabled app can
        // expose administrative or destructive endpoints under the same OCS
        // prefix.  Requiring an exact discovered route keeps the learning
        // loop useful while preventing arbitrary app API probing.
        $knownRoute = false;
        try {
            $learned = $this->getLearnedApis();
            $learnedAt = (int)($learned[$appId]['updated'] ?? 0);
            $routes = ($learnedAt > 0 && $learnedAt >= time() - self::LEARNED_API_TTL && is_array($learned[$appId]['routes'] ?? null)) ? $learned[$appId]['routes'] : [];
            foreach ($routes as $route) {
                if (!is_array($route)) continue;
                $routePath = (string)($route['path'] ?? '');
                $methods = is_array($route['methods'] ?? null) ? array_map('strtoupper', $route['methods']) : [];
                $routeComparable = str_starts_with($routePath, '/apps/' . $appId . '/')
                    ? substr($routePath, strlen('/apps/' . $appId)) : $routePath;
                if ($routePath !== '' && ($this->matchesDiscoveredRoute($routeComparable, $path) || $this->matchesDiscoveredRoute($routePath, $absolutePath)) && ($methods === [] || in_array($method, $methods, true))) {
                    $knownRoute = true;
                    break;
                }
            }
        } catch (\Throwable) { /* treat malformed learning cache as empty */ }
        if (!$knownRoute) {
            // Discovery is read-only and safe. Perform it transparently on
            // the first confirmed call so app integrations (for example
            // integration_immich) do not require the model to know an
            // internal discover-then-call dance.
            if (($args['_auto_discover'] ?? true) === true) {
                $discovered = $this->discoverAppApi(['app_id' => $appId, 'include_internal' => true]);
                if (($discovered['ok'] ?? false) === true) {
                    $args['_auto_discover'] = false;
                    return $this->callAppApi($args);
                }
            }
            return ['ok' => false, 'error' => $isOcsPath
                ? 'This OCS route has not been discovered recently. Call discover_app_api first.'
                : 'This non-OCS route has not been discovered yet. Call discover_app_api with include_internal=true first.'];
        }
        // Replace discovered {path} placeholders from the supplied parameter
        // object before constructing the same-origin URL. Path parameters are
        // never forwarded as query/body values and must be scalar to avoid
        // ambiguous or unsafe route expansion.
        $pathTemplate = $path;
        $path = $this->expandConnectorPath($pathTemplate, $params);
        if ($path === null) return ['ok' => false, 'error' => 'A required path parameter is missing or invalid.'];
        $params = $this->removePathParameters($pathTemplate, $params);
        if (!$isOcsPath && str_starts_with($path, '/api/')) $absolutePath = '/apps/' . $appId . $path;
        try {
            $appManager = Server::get(\OCP\App\IAppManager::class);
            if (!in_array($appId, array_map('strval', $appManager->getEnabledApps()), true) || !$appManager->isEnabledForUser($appId)) {
                return ['ok' => false, 'error' => 'That app is not enabled for the current user.'];
            }
            $request = Server::get(\OCP\IRequest::class);
            $client = Server::get(\OCP\Http\Client\IClientService::class)->newClient();
            $url = Server::get(\OCP\IURLGenerator::class)->getAbsoluteURL($absolutePath);
            $headers = ['Accept' => 'application/json', 'OCS-APIRequest' => 'true'];
            foreach (['Authorization', 'Cookie'] as $header) {
                $value = trim((string)$request->getHeader($header));
                if ($value !== '') $headers[$header] = $value;
            }
            // Background workers have no browser cookie. A user may opt in to
            // an encrypted Nextcloud app-password; it is used only for this
            // same-origin request and is never exposed to the model.
            if (!isset($headers['Authorization']) && !isset($headers['Cookie'])) {
                try {
                    $token = Server::get(\OCA\EvaAi\Service\ProviderCredentials::class)->getNextcloudToken($this->config->userId() ?? '');
                    $headers['Authorization'] = 'Basic ' . base64_encode(($this->config->userId() ?? '') . ':' . $token);
                } catch (\Throwable) {
                    return ['ok' => false, 'error' => 'No browser session or encrypted Nextcloud app token is available for this API action.'];
                }
            }
            $options = ['headers' => $headers, 'timeout' => self::APP_API_TIMEOUT, 'allow_redirects' => ['max' => 0, 'protocols' => ['https', 'http']]];
            if ($method === 'GET') $options['query'] = $params;
            elseif ($params !== []) {
                if ($isOcsPath) {
                    // OCS endpoints conventionally consume form-style fields.
                    $options['body'] = $params;
                } else {
                    // Internal app REST routes are commonly JSON APIs. Using
                    // an explicit JSON string avoids client-dependent array
                    // coercion and matches the generic connector adapter.
                    try {
                        $options['body'] = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                        $options['headers']['Content-Type'] = 'application/json';
                    } catch (\Throwable) {
                        return ['ok' => false, 'error' => 'App API parameters could not be encoded as JSON.'];
                    }
                }
            }
            $response = match ($method) {
                'GET' => $client->get($url, $options),
                'POST' => $client->post($url, $options),
                'PUT' => $client->put($url, $options),
                'PATCH' => $client->patch($url, $options),
                'DELETE' => $client->delete($url, $options),
            };
            $body = $response->getBody();
            if (is_resource($body)) $body = stream_get_contents($body);
            $body = mb_substr((string)$body, 0, 50000);
            $decoded = json_decode($body, true);
            // Generic app APIs may return credentials or session material even
            // on an otherwise harmless GET. Keep the adapter useful while
            // ensuring obvious secret-shaped fields never reach the model.
            $safeData = is_array($decoded) ? $this->redactApiPayload($decoded) : $body;
            $status = $response->getStatusCode();
            $ok = $status >= 200 && $status < 300;
            if ($ok) $this->rememberAppApiPattern($appId, $method, $path, array_keys($params), is_array($safeData) ? $this->shapeOf($safeData) : ['type' => 'string']);
            return ['ok' => $ok, 'result' => ['status' => $status, 'data' => $safeData, 'path' => $path, 'method' => $method]];
        } catch (\Throwable $e) {
            $detail = trim(preg_replace('/\s+/', ' ', $e->getMessage()));
            return ['ok' => false, 'error' => 'The app API request failed in the current user context.' . ($detail !== '' ? ' ' . mb_substr($detail, 0, 220) : '')];
        }
    }

    /** Execute bounded GET calls while reusing the same discovery/security path. */
    private function callAppApiBatch(array $args): array {
        $calls = $args['calls'] ?? null;
        if (!is_array($calls) || $calls === [] || count($calls) > 10) {
            return ['ok' => false, 'error' => 'calls must contain between 1 and 10 requests.'];
        }
        $results = [];
        $batchDeadline = microtime(true) + self::APP_API_BATCH_BUDGET;
        foreach ($calls as $call) {
            if (!is_array($call)) {
                $results[] = ['ok' => false, 'error' => 'Each batch item must be an object.'];
                continue;
            }
            if (microtime(true) >= $batchDeadline) {
                $results[] = ['ok' => false, 'error' => 'App API batch time budget reached; retry the remaining read requests separately.'];
                break;
            }
            $results[] = $this->callAppApi([
                'app_id' => $call['app_id'] ?? '',
                'path' => $call['path'] ?? '',
                'method' => 'GET',
                'params' => is_array($call['params'] ?? null) ? $call['params'] : [],
            ]);
        }
        return ['ok' => !in_array(false, array_map(static fn(array $row): bool => (bool)($row['ok'] ?? false), $results), true), 'result' => ['calls' => $results, 'count' => count($results)]];
    }

    /** Remember only reusable call shape, never parameter values or response data. */
    private function rememberAppApiPattern(string $appId, string $method, string $path, array $paramKeys, array $responseShape = []): void {
        if ($appId === '' || $path === '') return;
        try {
            $known = $this->getLearnedApis();
            if (!is_array($known[$appId] ?? null)) $known[$appId] = ['updated' => time(), 'routes' => []];
            $patterns = is_array($known[$appId]['patterns'] ?? null) ? $known[$appId]['patterns'] : [];
            $keys = array_values(array_unique(array_filter(array_map('strval', $paramKeys), static fn(string $key): bool => preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $key) === 1)));
            $entry = ['method' => $method, 'path' => $path, 'params' => $keys, 'response_shape' => $responseShape, 'last_used' => time()];
            $fingerprint = $method . ' ' . $path;
            $patterns = array_values(array_filter($patterns, static fn($row): bool => is_array($row) && (($row['method'] ?? '') . ' ' . ($row['path'] ?? '')) !== $fingerprint));
            array_unshift($patterns, $entry);
            $known[$appId]['patterns'] = array_slice($patterns, 0, 50);
            $known[$appId]['updated'] = time();
            if (count($known) > 30) {
                uasort($known, static fn (array $a, array $b): int => ((int)($b['updated'] ?? 0)) <=> ((int)($a['updated'] ?? 0)));
                $known = array_slice($known, 0, 30, true);
            }
            $this->config->set('learned_app_apis', json_encode($known, JSON_UNESCAPED_SLASHES) ?: '{}');
            $this->learnedApisCache = $known;
        } catch (\Throwable) { /* Learning is best effort and must not break the action. */ }
    }

    /** Return only JSON shape metadata; never retain response values. */
    private function shapeOf(mixed $value, int $depth = 0): array {
        if ($depth >= 3) return ['type' => is_array($value) ? 'object' : gettype($value)];
        if (!is_array($value)) return ['type' => gettype($value)];
        $keys = [];
        foreach (array_slice($value, 0, 40, true) as $key => $child) {
            $name = (string)$key;
            if (preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $name) !== 1) continue;
            $keys[$name] = $this->shapeOf($child, $depth + 1);
        }
        return ['type' => array_is_list($value) ? 'array' : 'object', 'keys' => $keys];
    }

    /** Remove credential-like fields from arbitrary JSON returned by an app. */
    private function redactApiPayload(mixed $value, int $depth = 0): mixed {
        if ($depth > 8) return '[redacted depth]';
        if (!is_array($value)) return $value;
        $out = [];
        foreach ($value as $key => $child) {
            $name = strtolower((string)$key);
            if (preg_match('/(?:pass(word)?|token|secret|api[_-]?key|authorization|cookie|private[_-]?key)/i', $name) === 1) {
                $out[$key] = '[redacted]';
            } else {
                $out[$key] = $this->redactApiPayload($child, $depth + 1);
            }
        }
        return $out;
    }

    /** Match a concrete request path against a Nextcloud route template. */
    private function matchesDiscoveredRoute(string $template, string $path): bool {
        $quoted = preg_quote(rtrim($template, '/'), '#');
        $quoted = preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', $quoted) ?? $quoted;
        return preg_match('#^' . $quoted . '/?$#', $path) === 1;
    }

    private function expandConnectorPath(string $template, array $params): ?string {
        $expanded = preg_replace_callback('/\{([A-Za-z0-9_.-]{1,80})\}/', static function (array $match) use ($params): string {
            $name = $match[1];
            if (!array_key_exists($name, $params) || is_array($params[$name]) || is_object($params[$name])) return $match[0];
            $value = trim((string)$params[$name]);
            return $value === '' ? $match[0] : rawurlencode($value);
        }, $template);
        if (!is_string($expanded) || preg_match('/\{[A-Za-z0-9_.-]{1,80}\}/', $expanded)) return null;
        return $expanded;
    }

    private function removePathParameters(string $template, array $params): array {
        preg_match_all('/\{([A-Za-z0-9_.-]{1,80})\}/', $template, $matches);
        foreach (($matches[1] ?? []) as $name) unset($params[$name]);
        return $params;
    }

    /** @return array{0:string,1:array}|null */
    private function splitRequestPath(string $path, array $params): ?array {
        if (str_contains($path, '#')) return null;
        $question = strpos($path, '?');
        if ($question === false) return [$path, $params];
        $clean = substr($path, 0, $question);
        $query = substr($path, $question + 1);
        if ($clean === '' || strlen($query) > 4000) return null;
        $parsed = [];
        if ($query !== '') {
            parse_str($query, $parsed);
            if (!is_array($parsed)) return null;
        }
        foreach ($parsed as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $key) !== 1) return null;
            if (!array_key_exists($key, $params)) $params[$key] = $value;
        }
        return [$clean, $params];
    }

}
