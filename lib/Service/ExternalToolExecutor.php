<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Server;

/** Handles discovery and bounded requests to configured external services. */
final class ExternalToolExecutor implements DomainToolExecutor {
    private const CONNECTOR_TIMEOUT = 8;
    private const CONNECTOR_CONNECT_TIMEOUT = 3;
    private const CONNECTOR_GET_ATTEMPTS = 2;
    private const CONNECTOR_DISCOVERY_BUDGET = 20;

    public function __construct(private AppConfig $config) {
    }

    public function tools(): array {
        return ['list_external_connectors', 'discover_external_connector', 'configure_external_connector', 'diagnose_external_connector', 'call_external_connector', 'call_external_connector_batch'];
    }

    public function hasConnector(string $id): bool {
        return array_key_exists(strtolower(trim($id)), $this->connectorRows());
    }

    public function execute(string $tool, string $userId, array $args): array {
        return match ($tool) {
            'list_external_connectors' => $this->listExternalConnectors(),
            'discover_external_connector' => $this->discoverExternalConnector($args),
            'configure_external_connector' => $this->configureExternalConnector($args),
            'diagnose_external_connector' => $this->diagnoseExternalConnector($args),
            'call_external_connector' => $this->callExternalConnector($args),
            'call_external_connector_batch' => $this->callExternalConnectorBatch($args),
            default => ['ok' => false, 'error' => 'Unsupported external connector tool: ' . $tool],
        };
    }

/** Execute bounded read-only calls against one or more configured connectors. */
    private function callExternalConnectorBatch(array $args): array {
        $calls = $args['calls'] ?? null;
        if (!is_array($calls) || $calls === [] || count($calls) > 8) return ['ok' => false, 'error' => 'calls must contain between 1 and 8 requests.'];
        $results = [];
        $batchDeadline = microtime(true) + self::CONNECTOR_DISCOVERY_BUDGET;
        foreach ($calls as $call) {
            if (!is_array($call)) { $results[] = ['ok' => false, 'error' => 'Each batch item must be an object.']; continue; }
            if (microtime(true) >= $batchDeadline) {
                $results[] = ['ok' => false, 'error' => 'Connector batch time budget reached; retry the remaining read requests separately.'];
                break;
            }
            $results[] = $this->callExternalConnector([
                'id' => $call['id'] ?? '', 'path' => $call['path'] ?? '', 'method' => 'GET',
                'params' => is_array($call['params'] ?? null) ? $call['params'] : [],
            ]);
        }
        return ['ok' => !in_array(false, array_map(static fn(array $row): bool => (bool)($row['ok'] ?? false), $results), true), 'result' => ['calls' => $results, 'count' => count($results)]];
    }

private function connectorRows(): array {
        if ($this->connectorRowsCache !== null) {
            return $this->connectorRowsCache;
        }
        $user = $this->config->userId() ?? '';
        if ($user === '') {
            $this->connectorRowsCache = [];
            return [];
        }
        $raw = Server::get(\OCP\IConfig::class)->getUserValue($user, AppConfig::APP, 'external_connectors', '{}');
        $rows = json_decode($raw, true);
        $this->connectorRowsCache = is_array($rows) ? $rows : [];
        return $this->connectorRowsCache;
    }

    private function listExternalConnectors(): array {
        $out = [];
        $user = $this->config->userId() ?? '';
        $credentials = Server::get(ProviderCredentials::class);
        foreach ($this->connectorRows() as $id => $row) {
            if (!is_array($row)) continue;
            $endpoints = is_array($row['openapi']['endpoints'] ?? null) ? array_values(array_slice($row['openapi']['endpoints'], -1000)) : [];
            $prefix = 'connector_' . (string)$id;
            // Bearer tokens were historically stored in the generic api_key
            // slot. Accept both that legacy slot and the explicit token slot
            // so settings edits cannot make a valid connector look unauthenticated.
            $tokenConfigured = $credentials->customValueConfigured($user, $prefix, 'token')
                || $credentials->customValueConfigured($user, $prefix, 'api_key');
            $usernameConfigured = $credentials->customValueConfigured($user, $prefix, 'username');
            $passwordConfigured = $credentials->customValueConfigured($user, $prefix, 'password');
            $apiKeyConfigured = $credentials->customValueConfigured($user, $prefix, 'api_key');
            $authType = (string)($row['auth_type'] ?? ($tokenConfigured ? 'bearer' : 'none'));
            $out[] = ['id' => (string)$id, 'name' => (string)($row['name'] ?? $id), 'base_url' => (string)($row['base_url'] ?? ''), 'openapi_url' => (string)($row['openapi_url'] ?? ''), 'auth_type' => $authType, 'token_configured' => $tokenConfigured, 'username_configured' => $usernameConfigured, 'password_configured' => $passwordConfigured, 'api_key_configured' => $apiKeyConfigured, 'api_key_header' => $this->normalizedApiKeyHeader($row), 'updated_at' => (int)($row['updated_at'] ?? 0), 'discovered_endpoint_count' => count($endpoints), 'learned_endpoints' => $endpoints, 'openapi_source' => mb_substr((string)($row['openapi']['source'] ?? ''), 0, 300), 'openapi_updated_at' => (int)($row['openapi']['updated_at'] ?? 0)];
        }
        return ['ok' => true, 'result' => ['connectors' => $out]];
    }

    private function discoverExternalConnector(array $args): array {
        $id = strtolower(trim((string)($args['id'] ?? ''))); $row = $this->connectorRows()[$id] ?? null;
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D', $id) || !is_array($row) || !$this->safeConnectorUrl((string)($row['base_url'] ?? ''))) return ['ok' => false, 'error' => 'Connector is not configured.'];
        $user = $this->config->userId() ?? ''; $headers = ['Accept' => 'application/json'];
        try {
            $discoveryDeadline = microtime(true) + self::CONNECTOR_DISCOVERY_BUDGET;
            $headers = array_merge($headers, $this->connectorAuthHeaders($id, $row, $user));
            $found = null; $source = null;
            // Establish transport reachability once before probing multiple
            // documentation paths. An offline host now fails quickly with a
            // useful message instead of appearing to do nothing for minutes.
            [$rootProbeStatus, $rootProbeBody] = $this->connectorCurlGet(rtrim((string)$row['base_url'], '/') . '/', $headers, 4);
            if ($rootProbeStatus === 0) {
                return ['ok' => false, 'error' => 'Connector host is unreachable from the Nextcloud server. Check DNS, routing, VPN and firewall settings.'];
            }
            // Browser-oriented services (including Immich) may return 406 to
            // an API-style Accept header while serving their landing page as
            // HTML. Retry that one probe with a browser Accept value so the
            // service can still identify itself without a vendor preset.
            if ($rootProbeStatus === 406) {
                [$htmlStatus, $htmlBody] = $this->connectorCurlGet(rtrim((string)$row['base_url'], '/') . '/', ['Accept' => 'text/html,application/xhtml+xml'], 4);
                if ($htmlStatus >= 200 && $htmlStatus < 400 && $htmlBody !== '') {
                    $rootProbeStatus = $htmlStatus;
                    $rootProbeBody = $htmlBody;
                }
            }
            // Probe standard schema locations uniformly. Using curl here is
            // intentional: Nextcloud's HTTP client can reject private/LAN
            // addresses even when the connector was explicitly allow-listed.
            // There are no vendor-specific adapters; any service publishing a
            // standard OpenAPI/Swagger document is learned the same way.
            $candidates = [];
            $customSchema = trim((string)($row['openapi_url'] ?? ''));
            if ($customSchema !== '' && $this->sameConnectorHost($customSchema, (string)$row['base_url'])) $candidates[] = $customSchema;
            $candidates = array_merge($candidates, ['/api/v2.0', '/openapi.json', '/openapi.yaml', '/openapi.yml', '/swagger.json', '/swagger.yaml', '/swagger.yml', '/.well-known/openapi.json', '/.well-known/openapi.yaml', '/api/open-api', '/api/openapi.json', '/api/openapi.yaml', '/api/openapi.yml', '/api/swagger.json', '/api/swagger.yaml', '/api/swagger.yml', '/api/openapi', '/api/swagger', '/docs', '/docs/openapi.json', '/docs/openapi.yaml', '/api/docs', '/api/docs/openapi.json', '/api/docs/openapi.yaml', '/api/v2.0/docs', '/api', '/graphql', '/api/graphql']);
            // Some services advertise their schema only as a link in the
            // landing page. Extract same-host JSON/YAML documentation links
            // without trusting arbitrary external URLs or executing them.
            if (is_string($rootProbeBody) && $rootProbeBody !== '') {
                preg_match_all('~(?:href|src)=["\']([^"\']*(?:openapi|swagger|api-docs|docs)[^"\']*)["\']~i', $rootProbeBody, $linkMatches);
                foreach (($linkMatches[1] ?? []) as $linked) {
                    $parts = parse_url(html_entity_decode((string)$linked, ENT_QUOTES | ENT_HTML5));
                    $linkedPath = (string)($parts['path'] ?? '');
                    if ($linkedPath !== '' && str_starts_with($linkedPath, '/') && !in_array($linkedPath, $candidates, true)) $candidates[] = $linkedPath;
                }
                // Hypermedia APIs often publish no schema but expose a
                // bounded set of navigable links in the root JSON document.
                // Learn same-host GET routes as a generic fallback.
                $rootJson = json_decode(mb_substr($rootProbeBody, 0, 1048576), true);
                $collectLinks = function ($value) use (&$collectLinks, &$candidates): void {
                    if (!is_array($value)) return;
                    foreach ($value as $key => $item) {
                        if (is_string($item) && in_array(strtolower((string)$key), ['href', 'url', 'uri', 'path', 'self', 'next', 'endpoint'], true)
                            && str_starts_with($item, '/') && !str_starts_with($item, '//') && strlen($item) <= 300
                            && !in_array($item, $candidates, true)) $candidates[] = $item;
                        if (is_array($item)) $collectLinks($item);
                    }
                };
                if (is_array($rootJson)) $collectLinks($rootJson);
                // Single-page applications often ship their route map only in
                // same-origin JavaScript bundles, without publishing a schema.
                // Read a few bounded bundles and learn literal API paths. This
                // is vendor-neutral and never follows a third-party host.
                preg_match_all('~(?:src|href)=["\']([^"\']+\\.js(?:\\?[^"\']*)?)["\']~i', $rootProbeBody, $assetMatches);
                foreach (array_slice(array_values(array_unique($assetMatches[1] ?? [])), 0, 3) as $asset) {
                    $assetParts = parse_url(html_entity_decode((string)$asset, ENT_QUOTES | ENT_HTML5));
                    $assetPath = (string)($assetParts['path'] ?? '');
                    if ($assetPath === '' || !str_starts_with($assetPath, '/')) continue;
                    $assetUrl = rtrim((string)$row['base_url'], '/') . $assetPath;
                    if (!$this->safeConnectorUrl($assetUrl)) continue;
                    [, $assetBody] = $this->connectorCurlGet($assetUrl, ['Accept' => 'application/javascript,text/javascript,*/*'], 4);
                    if (!is_string($assetBody) || $assetBody === '') continue;
                    preg_match_all('~["\'](\/(?:api|rest|ocs)(?:\/[A-Za-z0-9_.$:{}~+@%\-]+){1,12})["\']~', mb_substr($assetBody, 0, 1048576), $routeMatches);
                    foreach (array_slice(array_values(array_unique($routeMatches[1] ?? [])), 0, 120) as $route) {
                        if (!in_array($route, $candidates, true)) $candidates[] = $route;
                    }
                }
            }
            $authDiscoveryStatus = 0;
            $reachableRoutes = [];
            $graphqlEndpoints = [];
            foreach ($found === null ? array_slice(array_values(array_unique($candidates)), 0, 80) : [] as $candidate) {
                if (microtime(true) >= $discoveryDeadline) break;
                $url = preg_match('~^https?://~i', $candidate) ? $candidate : rtrim((string)$row['base_url'], '/') . $candidate; if (!$this->safeConnectorUrl($url)) continue;
                [$status, $body] = $this->connectorCurlGet($url, $headers, 4);
                if ($status === 401 || $status === 403) $authDiscoveryStatus = $status;
                $graphql = $this->graphqlEndpointMeta((string)$candidate, $status);
                if ($graphql !== null) $graphqlEndpoints[] = $graphql;
                if ($status >= 200 && $status < 500 && $status !== 404 && str_starts_with((string)$candidate, '/')) {
                    $reachableRoutes[] = ['path' => mb_substr((string)$candidate, 0, 300), 'method' => 'GET', 'operation_id' => 'runtime_probe', 'requires_auth' => in_array($status, [401, 403], true)];
                }
                if ($status < 200 || $status >= 300) continue;
                $decoded = $this->decodeConnectorSchema((string)$body);
                if (is_array($decoded) && is_array($decoded['paths'] ?? null)) { $found = $decoded; $source = $candidate; break; }
            }
            if ($found === null) {
                // A protected API can still prove its route shape through a
                // 401/403 response. Persist those same-origin probes so the
                // agent can call them after credentials are corrected.
                // Handle GraphQL first: its GET probe commonly returns 405,
                // which is also a reachable generic route, but the learned
                // POST body schema is more useful than a GET runtime probe.
                if ($graphqlEndpoints !== []) {
                    $rows = $this->connectorRows();
                    $rows[$id]['openapi'] = ['source' => 'runtime-graphql', 'version' => '', 'endpoints' => array_values(array_unique($graphqlEndpoints, SORT_REGULAR)), 'updated_at' => time()];
                    Server::get(\OCP\IConfig::class)->setUserValue($user, AppConfig::APP, 'external_connectors', json_encode($rows, JSON_UNESCAPED_SLASHES) ?: '{}');
                    return ['ok' => true, 'result' => ['connector' => $id, 'source' => 'runtime-graphql', 'title' => 'GraphQL', 'endpoints' => $graphqlEndpoints, 'note' => 'A GraphQL endpoint was learned. POST requests require a query field; variables and operationName are optional.']];
                }
                if ($reachableRoutes !== [] && (is_string($rootProbeBody) ? stripos($rootProbeBody, 'immich') === false : true)) {
                    $reachableRoutes = array_values(array_unique(array_merge($reachableRoutes, $graphqlEndpoints), SORT_REGULAR));
                    $rows = $this->connectorRows();
                    $rows[$id]['openapi'] = ['source' => 'runtime-probe', 'version' => '', 'endpoints' => array_values(array_unique($reachableRoutes, SORT_REGULAR)), 'updated_at' => time()];
                    Server::get(\OCP\IConfig::class)->setUserValue($user, AppConfig::APP, 'external_connectors', json_encode($rows, JSON_UNESCAPED_SLASHES) ?: '{}');
                    return ['ok' => true, 'result' => ['connector' => $id, 'source' => 'runtime-probe', 'title' => '', 'endpoints' => $reachableRoutes, 'note' => 'The service exposes no readable schema, but reachable same-origin routes were learned. Protected routes require valid credentials.']];
                }
                // Immich deployments often disable Swagger in production but
                // expose a stable REST surface. Identify Immich from the
                // returned landing page and learn only its read/search routes
                // (writes remain confirmation-gated as usual).
                if (is_string($rootProbeBody) && stripos($rootProbeBody, 'immich') !== false) {
                    // Production Immich installations may disable Swagger.
                    // Probe its documented controller roots and common
                    // read-only subroutes: protected routes answer 401/403,
                    // which is enough to prove that the route exists without
                    // exposing data. This keeps discovery useful for every
                    // Immich deployment while never issuing mutating calls.
                    $immichCandidates = [
                        '/api/activities', '/api/albums', '/api/albums/statistics', '/api/assets', '/api/assets/statistics',
                        '/api/auth/status', '/api/duplicates', '/api/faces', '/api/jobs', '/api/libraries', '/api/map/markers',
                        '/api/memories', '/api/notifications', '/api/people', '/api/partners', '/api/search', '/api/search/smart',
                        '/api/search/metadata', '/api/server/about', '/api/server/config', '/api/server/features', '/api/server/statistics',
                        '/api/sessions', '/api/shared-links', '/api/stacks', '/api/system-config', '/api/system-metadata', '/api/tags',
                        '/api/timeline/bucket', '/api/timeline/buckets', '/api/trash', '/api/users', '/api/views', '/api/workflows',
                        '/api/asset-files', '/api/download/info', '/api/notifications', '/api/oauth/mobile-redirect',
                        '/api/api-keys', '/api/api-keys/me', '/api/activities/statistics', '/api/albums/map-markers', '/api/faces',
                        '/api/cluster-groups/requests', '/api/config', '/api/config/defaults', '/api/public/config', '/api/public/config/defaults',
                        '/api/duplicates', '/api/integrity/summary', '/api/integrity/report', '/api/libraries', '/api/memories/statistics',
                        '/api/notifications', '/api/partners', '/api/plugins/methods', '/api/plugins/templates', '/api/queues',
                        '/api/queues/thumbnail/jobs', '/api/queues/smartSearch/jobs', '/api/search/explore', '/api/search/person',
                        '/api/search/places', '/api/search/cities', '/api/search/suggestions', '/api/server/apk-links', '/api/server/storage',
                        '/api/server/ping', '/api/server/version', '/api/server/version-history', '/api/server/media-types', '/api/server/license',
                        '/api/server/version-check', '/api/sessions', '/api/shared-links', '/api/shared-links/me', '/api/stacks',
                        '/api/sync/ack', '/api/system-config/defaults', '/api/system-config/storage-template-options', '/api/system-metadata/admin-onboarding',
                        '/api/system-metadata/reverse-geocoding-state', '/api/system-metadata/version-check-state', '/api/tags', '/api/timeline/buckets',
                        '/api/trash/restore', '/api/trash/restore/assets', '/api/view/folder/unique-paths', '/api/view/folder', '/api/workflows/triggers',
                    ];
                    $immichEndpoints = [];
                    foreach (array_slice(array_values(array_unique($immichCandidates)), 0, 24) as $candidate) {
                        if (microtime(true) >= $discoveryDeadline) break;
                        [$probeStatus] = $this->connectorCurlGet(rtrim((string)$row['base_url'], '/') . $candidate, $headers, 4);
                        if (($probeStatus >= 200 && $probeStatus < 500) && $probeStatus !== 404) $immichEndpoints[] = ['path' => $candidate, 'method' => 'GET', 'operation_id' => 'immich_discovered'];
                    }
                    // Parameterized and write/search routes are retained as
                    // capabilities for the agent but are never probed.
                    foreach ([
                        ['/api/people/{id}', 'GET', 'person'], ['/api/people/{id}/thumbnail', 'GET', 'person_thumbnail'],
                        ['/api/assets/{id}', 'GET', 'asset'], ['/api/assets/{id}/thumbnail', 'GET', 'asset_thumbnail'],
                        ['/api/search/person', 'POST', 'search_person'], ['/api/search/face', 'POST', 'search_face'],
                        ['/api/search/clip', 'POST', 'search_clip'], ['/api/assets', 'POST', 'upload_asset'],
                    ] as [$path, $method, $operation]) $immichEndpoints[] = ['path' => $path, 'method' => $method, 'operation_id' => $operation];
                    if ($immichEndpoints === []) $immichEndpoints[] = ['path' => '/api/people', 'method' => 'GET', 'operation_id' => 'people'];
                    $rows = $this->connectorRows();
                    // Immich often disables Swagger in production but its
                    // REST API consistently uses x-api-key. Infer that
                    // scheme for a fresh connector so the Settings form and
                    // subsequent calls use the right field automatically.
                    $currentRow = is_array($rows[$id] ?? null) ? $rows[$id] : [];
                    $hasStoredSecret = !empty($currentRow['token_configured']) || !empty($currentRow['api_key_configured'])
                        || !empty($currentRow['username_configured']) || !empty($currentRow['password_configured']);
                    if (!$hasStoredSecret && (($currentRow['auth_type'] ?? 'bearer') === 'bearer')) {
                        $currentRow['auth_type'] = 'api_key';
                        $currentRow['api_key_header'] = 'x-api-key';
                        $rows[$id] = $currentRow;
                    }
                    $rows[$id]['openapi'] = ['source' => 'runtime-immich', 'version' => '', 'endpoints' => $immichEndpoints, 'updated_at' => time()];
                    Server::get(\OCP\IConfig::class)->setUserValue($user, AppConfig::APP, 'external_connectors', json_encode($rows, JSON_UNESCAPED_SLASHES) ?: '{}');
                    return ['ok' => true, 'result' => ['connector' => $id, 'source' => 'runtime-immich', 'title' => 'Immich', 'endpoints' => $immichEndpoints, 'auth_type' => $rows[$id]['auth_type'] ?? 'bearer', 'note' => 'Immich API routes were learned. Configure an Immich API key using the x-api-key header before calling protected endpoints.']];
                }
                // Many appliances expose no schema at all. A bounded GET of
                // the configured root is still useful discovery and gives the
                // user a concrete learned endpoint to inspect next.
                $rootUrl = rtrim((string)$row['base_url'], '/') . '/';
                [$rootStatus] = $this->connectorCurlGet($rootUrl, $headers, 8);
                if ($rootStatus >= 200 && $rootStatus < 300) {
                    $fallbackEndpoints = [['path' => '/', 'method' => 'GET', 'operation_id' => 'root']];
                    foreach (array_slice(array_values(array_unique($candidates)), 0, 20) as $candidate) {
                        if ($candidate !== '/' && str_starts_with($candidate, '/') && !str_contains($candidate, '{')) $fallbackEndpoints[] = ['path' => mb_substr($candidate, 0, 300), 'method' => 'GET', 'operation_id' => 'discovered_link'];
                    }
                    $rows = $this->connectorRows(); $rows[$id]['openapi'] = ['source' => 'runtime', 'version' => '', 'endpoints' => $fallbackEndpoints, 'updated_at' => time()];
                    Server::get(\OCP\IConfig::class)->setUserValue($user, AppConfig::APP, 'external_connectors', json_encode($rows, JSON_UNESCAPED_SLASHES) ?: '{}');
                    return ['ok' => true, 'result' => ['connector' => $id, 'source' => 'runtime', 'title' => '', 'endpoints' => $rows[$id]['openapi']['endpoints'], 'note' => 'No API schema was published; the service root was learned and can be tested.']];
                }
                if ($rootProbeStatus === 401 || $rootProbeStatus === 403) {
                    return ['ok' => false, 'error' => 'Connector is reachable (HTTP ' . $rootProbeStatus . ') but requires valid credentials before its API can be discovered.'];
                }
                if ($authDiscoveryStatus !== 0) {
                    return ['ok' => false, 'error' => 'Connector is reachable, but its API description requires authentication (HTTP ' . $authDiscoveryStatus . ').'];
                }
                return ['ok' => false, 'error' => 'No OpenAPI or Swagger description was found (root HTTP ' . $rootProbeStatus . '). The service may disable schema discovery or use a custom API base path.'];
            }
            $endpoints = [];
            // Prefer the authentication scheme advertised by the service when
            // the user has not stored credentials yet. This makes first-time
            // connector setup intuitive (Immich commonly advertises x-api-key)
            // while never replacing an explicitly configured secret or scheme.
            $inferredAuth = $this->inferConnectorAuth($found);
            if ($inferredAuth !== null) {
                $current = $this->connectorRows();
                $currentRow = is_array($current[$id] ?? null) ? $current[$id] : [];
                $hasStoredSecret = !empty($currentRow['token_configured']) || !empty($currentRow['api_key_configured'])
                    || !empty($currentRow['username_configured']) || !empty($currentRow['password_configured']);
                if (!$hasStoredSecret && (($currentRow['auth_type'] ?? 'bearer') === 'bearer')) {
                    $currentRow['auth_type'] = $inferredAuth['auth_type'];
                    if (isset($inferredAuth['api_key_header'])) $currentRow['api_key_header'] = $inferredAuth['api_key_header'];
                    $current[$id] = $currentRow;
                    Server::get(\OCP\IConfig::class)->setUserValue($user, AppConfig::APP, 'external_connectors', json_encode($current, JSON_UNESCAPED_SLASHES) ?: '{}');
                }
            }
            // Do not slice the schema's paths before iterating: TrueNAS places
            // VM and container routes after the first hundred entries. Bound
            // the persisted result instead, so discovery covers the complete
            // document without allowing unbounded user-config data growth.
            foreach ($found['paths'] as $path => $operations) {
                if (count($endpoints) >= 1000) break;
                if (!is_string($path) || !is_array($operations) || !str_starts_with($path, '/')) continue;
                $pathParameters = is_array($operations['parameters'] ?? null) ? $operations['parameters'] : [];
                // The TrueNAS document is served from /api/v2.0 but its
                // paths are relative to that mount point. Persist absolute
                // connector paths so subsequent calls do not accidentally
                // hit the appliance root (which yields a misleading 404).
                $schemaPrefix = $source === '/api/v2.0' ? '/api/v2.0' : (str_starts_with((string)$source, '/api/') ? '/api' : '');
                // OpenAPI 3 declares the mounted API prefix in servers.url;
                // Swagger 2 uses basePath. Honour only a path component so a
                // malicious schema cannot redirect calls to another host.
                $declaredPrefix = $this->connectorSchemaPrefix($found, $schemaPrefix);
                $prefix = $declaredPrefix !== '' ? $declaredPrefix : $schemaPrefix;
                $routePath = $prefix !== '' && !str_starts_with($path, $prefix . '/') && $path !== $prefix
                    ? $prefix . $path : $path;
                foreach ($operations as $method => $operation) if (in_array(strtoupper((string)$method), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    $meta = ['path' => mb_substr($routePath, 0, 300), 'method' => strtoupper((string)$method), 'operation_id' => is_array($operation) ? mb_substr((string)($operation['operationId'] ?? ''), 0, 120) : ''];
                    if (is_array($operation)) {
                        $params = [];
                        $operationParameters = array_merge($pathParameters, is_array($operation['parameters'] ?? null) ? $operation['parameters'] : []);
                        foreach (array_slice($operationParameters, 0, 20) as $parameter) {
                            if (!is_array($parameter)) continue;
                            $name = (string)($parameter['name'] ?? '');
                            if (preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $name) !== 1) continue;
                            $schema = is_array($parameter['schema'] ?? null) ? $parameter['schema'] : [];
                            // Swagger 2 keeps `type` on the parameter itself;
                            // OpenAPI 3 nests it under `schema`.
                            $type = (string)($schema['type'] ?? $parameter['type'] ?? 'string');
                            $params[] = ['name' => $name, 'in' => in_array(($parameter['in'] ?? ''), ['query', 'path', 'header', 'cookie'], true) ? (string)$parameter['in'] : 'query', 'required' => !empty($parameter['required']), 'type' => preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $type) === 1 ? $type : 'string'];
                        }
                        if ($params !== []) $meta['parameters'] = $params;
                        $requestBody = $this->connectorRequestBodyMeta($operation, $found);
                        if ($requestBody !== null) $meta['request_body'] = $requestBody;
                    }
                    $endpoints[] = $meta;
                }
            }
            $rows = $this->connectorRows(); $rows[$id]['openapi'] = ['source' => $source, 'version' => mb_substr((string)($found['openapi'] ?? $found['swagger'] ?? ''), 0, 30), 'endpoints' => array_slice($endpoints, 0, 1000), 'updated_at' => time()];
            Server::get(\OCP\IConfig::class)->setUserValue($user, AppConfig::APP, 'external_connectors', json_encode($rows, JSON_UNESCAPED_SLASHES) ?: '{}');
            return ['ok' => true, 'result' => ['connector' => $id, 'source' => $source, 'title' => mb_substr((string)($found['info']['title'] ?? ''), 0, 160), 'endpoints' => array_slice($endpoints, 0, 1000)]];
        } catch (\Throwable) { return ['ok' => false, 'error' => 'External API discovery failed.']; }
    }

    /** Decode JSON schemas everywhere; use PHP's optional YAML extension when available. */
    private function decodeConnectorSchema(string $body): ?array {
        $body = mb_substr($body, 0, 8388608);
        $decoded = json_decode($body, true);
        if (is_array($decoded)) return $decoded;
        if (function_exists('yaml_parse')) {
            try {
                $yaml = yaml_parse($body);
                return is_array($yaml) ? $yaml : null;
            } catch (\Throwable) { return null; }
        }
        // Ship a parser fallback so OpenAPI YAML works on standard PHP
        // installations where the optional ext-yaml extension is unavailable.
        if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            try {
                $yaml = \Symfony\Component\Yaml\Yaml::parse($body);
                return is_array($yaml) ? $yaml : null;
            } catch (\Throwable) { return null; }
        }
        return null;
    }

    /** Resolve a safe path prefix from OpenAPI servers.url/basePath metadata. */
    private function connectorSchemaPrefix(array $document, string $fallback = ''): string {
        $prefix = '';
        $server = is_array($document['servers'][0] ?? null) ? $document['servers'][0] : null;
        if ($server !== null) {
            $serverUrl = (string)($server['url'] ?? '');
            $serverParts = parse_url($serverUrl);
            $prefix = (string)($serverParts['path'] ?? '');
            $variables = is_array($server['variables'] ?? null) ? $server['variables'] : [];
            $invalidVariable = false;
            // OpenAPI permits templated server paths such as /api/{version}.
            // Resolve only declared defaults and never interpolate arbitrary
            // user-provided values into connector URLs.
            $prefix = preg_replace_callback('/\{([A-Za-z][A-Za-z0-9_-]{0,63})\}/', static function (array $match) use ($variables, &$invalidVariable): string {
                $variable = $variables[$match[1]] ?? null;
                $default = is_array($variable) ? trim((string)($variable['default'] ?? '')) : '';
                if (preg_match('/^[A-Za-z0-9._~-]{1,80}$/D', $default) !== 1) {
                    $invalidVariable = true;
                    return '';
                }
                return $default;
            }, $prefix) ?? '';
            if ($invalidVariable) $prefix = '';
        }
        if ($prefix === '') $prefix = (string)($document['basePath'] ?? '');
        $prefix = '/' . trim($prefix, '/');
        if ($prefix === '/' || str_contains($prefix, '..') || preg_match('/[\r\n?#]/', $prefix) || mb_strlen($prefix) > 200) {
            $prefix = '';
        }
        return $prefix !== '' ? $prefix : $fallback;
    }

    /** @return array{path:string,method:string,operation_id:string,request_body:array}|null */
    private function graphqlEndpointMeta(string $candidate, int $status): ?array {
        $path = (string)(parse_url($candidate, PHP_URL_PATH) ?: $candidate);
        if (!preg_match('~(?:^|/)graphql/?$~i', $path) || in_array($status, [0, 404], true) || $status < 200 || $status >= 500) return null;
        return ['path' => mb_substr($path, 0, 300), 'method' => 'POST', 'operation_id' => 'graphql', 'request_body' => [
            'required' => true,
            'content_type' => 'application/json',
            'fields' => [
                ['name' => 'query', 'type' => 'string', 'required' => true],
                ['name' => 'variables', 'type' => 'object', 'required' => false],
                ['name' => 'operationName', 'type' => 'string', 'required' => false],
            ],
        ]];
    }

    /** @return array{auth_type:string,api_key_header?:string}|null */
    private function inferConnectorAuth(array $document): ?array {
        $schemes = [];
        if (is_array($document['components']['securitySchemes'] ?? null)) $schemes = $document['components']['securitySchemes'];
        elseif (is_array($document['securityDefinitions'] ?? null)) $schemes = $document['securityDefinitions'];
        foreach ($schemes as $scheme) {
            if (!is_array($scheme)) continue;
            $type = strtolower((string)($scheme['type'] ?? ''));
            if ($type === 'apikey' || $type === 'apiKey') {
                $header = (string)($scheme['name'] ?? 'X-API-Key');
                return ['auth_type' => 'api_key', 'api_key_header' => $this->normalizedApiKeyHeader(['api_key_header' => $header])];
            }
        }
        foreach ($schemes as $scheme) {
            if (!is_array($scheme)) continue;
            $type = strtolower((string)($scheme['type'] ?? ''));
            if ($type === 'basic' || ($type === 'http' && strtolower((string)($scheme['scheme'] ?? '')) === 'basic')) return ['auth_type' => 'basic'];
        }
        foreach ($schemes as $scheme) {
            if (!is_array($scheme)) continue;
            $type = strtolower((string)($scheme['type'] ?? ''));
            if ($type === 'http' && in_array(strtolower((string)($scheme['scheme'] ?? '')), ['bearer', 'token'], true)) return ['auth_type' => 'bearer'];
            if ($type === 'oauth2' || $type === 'openidconnect') return ['auth_type' => 'bearer'];
        }
        return null;
    }

    /**
     * Extract a bounded description of an OpenAPI JSON request body. The
     * values are schema metadata only; defaults/examples are deliberately not
     * copied into learned connector state.
     *
     * @return array{required:bool,content_type:string,fields:list<array{name:string,type:string,required:bool}>}|null
     */
    private function connectorRequestBodyMeta(array $operation, array $document = []): ?array {
        $schema = null;
        $required = false;
        $contentType = 'application/json';
        $requestBody = $operation['requestBody'] ?? null;
        if (is_array($requestBody)) {
            $required = !empty($requestBody['required']);
            $content = is_array($requestBody['content'] ?? null) ? $requestBody['content'] : [];
            foreach (['application/json', 'application/*+json', '*/*'] as $candidate) {
                if (is_array($content[$candidate]['schema'] ?? null)) {
                    $schema = $this->resolveConnectorSchema($content[$candidate]['schema'], $document);
                    $contentType = $candidate;
                    break;
                }
            }
        }
        // Swagger 2 describes JSON bodies as an operation parameter with
        // in=body rather than requestBody/content.
        if ($schema === null && is_array($operation['parameters'] ?? null)) {
            foreach ($operation['parameters'] as $parameter) {
                if (is_array($parameter) && ($parameter['in'] ?? '') === 'body' && is_array($parameter['schema'] ?? null)) {
                    $schema = $this->resolveConnectorSchema($parameter['schema'], $document);
                    $required = !empty($parameter['required']);
                    break;
                }
            }
        }
        if (!is_array($schema)) return null;
        // A schema may combine referenced fragments. Merge only bounded,
        // local object fragments; never fetch a remote $ref.
        foreach (['allOf', 'oneOf', 'anyOf'] as $combiner) {
            if (!is_array($schema[$combiner] ?? null)) continue;
            foreach (array_slice($schema[$combiner], 0, 8) as $fragment) {
                if (!is_array($fragment)) continue;
                $fragment = $this->resolveConnectorSchema($fragment, $document);
                if (!is_array($fragment)) continue;
                if (is_array($fragment['properties'] ?? null)) $schema['properties'] = array_merge($schema['properties'] ?? [], $fragment['properties']);
                if (is_array($fragment['required'] ?? null)) $schema['required'] = array_values(array_unique(array_merge($schema['required'] ?? [], $fragment['required'])));
            }
        }
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $requiredFields = array_fill_keys(is_array($schema['required'] ?? null) ? array_map('strval', $schema['required']) : [], true);
        $fields = [];
        foreach (array_slice($properties, 0, 40, true) as $name => $property) {
            if (!is_array($property) || preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', (string)$name) !== 1) continue;
            $type = (string)($property['type'] ?? 'object');
            if (preg_match('/^[A-Za-z0-9_.-]{1,40}$/D', $type) !== 1) $type = 'object';
            $fields[] = ['name' => (string)$name, 'type' => $type, 'required' => isset($requiredFields[(string)$name])];
        }
        return ['required' => $required, 'content_type' => $contentType, 'fields' => $fields];
    }

    /** Resolve at most a few local JSON pointers from a learned OpenAPI document. */
    private function resolveConnectorSchema(array $schema, array $document, int $depth = 0): array {
        if ($depth >= 4 || !isset($schema['$ref']) || !is_string($schema['$ref'])) return $schema;
        $ref = $schema['$ref'];
        if (!str_starts_with($ref, '#/') || str_contains($ref, '..')) return $schema;
        $value = $document;
        foreach (array_slice(explode('/', substr($ref, 2)), 0, 20) as $part) {
            $part = str_replace(['~1', '~0'], ['/', '~'], $part);
            if (!is_array($value) || !array_key_exists($part, $value)) return $schema;
            $value = $value[$part];
        }
        if (!is_array($value)) return $schema;
        $resolved = $this->resolveConnectorSchema($value, $document, $depth + 1);
        // Inline constraints/properties override referenced defaults.
        foreach ($schema as $key => $item) if ($key !== '$ref') $resolved[$key] = $item;
        return $resolved;
    }

    private function configureExternalConnector(array $args): array {
        $id = strtolower(trim((string)($args['id'] ?? '')));
        $base = rtrim(trim((string)($args['base_url'] ?? '')), '/');
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D', $id) || !$this->safeConnectorUrl($base)) return ['ok' => false, 'error' => 'Connector id or base_url is invalid; use public HTTPS or a local HTTP(S) host.'];
        $name = trim((string)($args['name'] ?? $id));
        if ($name === '') $name = $id;
        $rows = $this->connectorRows();
        $previous = is_array($rows[$id] ?? null) ? $rows[$id] : [];
        $authType = in_array((string)($args['auth_type'] ?? ($previous['auth_type'] ?? 'bearer')), ['none', 'bearer', 'basic', 'api_key'], true) ? (string)($args['auth_type'] ?? ($previous['auth_type'] ?? 'bearer')) : 'bearer';
        $schemaUrl = trim((string)($args['openapi_url'] ?? ($previous['openapi_url'] ?? '')));
        if ($schemaUrl !== '' && (!$this->safeConnectorUrl($schemaUrl) || !$this->sameConnectorHost($schemaUrl, $base))) return ['ok' => false, 'error' => 'The OpenAPI URL must use the same host as the connector base URL.'];
        $rows[$id] = ['name' => mb_substr($name, 0, 120), 'base_url' => $base, 'openapi_url' => $schemaUrl, 'auth_type' => $authType,
            'token_configured' => isset($args['token']) && trim((string)$args['token']) !== '' ? true : !empty($previous['token_configured']),
            'username_configured' => isset($args['username']) && trim((string)$args['username']) !== '' ? true : !empty($previous['username_configured']),
            'password_configured' => isset($args['password']) && trim((string)$args['password']) !== '' ? true : !empty($previous['password_configured']),
            'api_key_configured' => isset($args['api_key']) && trim((string)$args['api_key']) !== '' ? true : !empty($previous['api_key_configured']),
            'api_key_header' => $this->normalizedApiKeyHeader(['api_key_header' => (string)($args['api_key_header'] ?? ($previous['api_key_header'] ?? 'X-API-Key'))]), 'updated_at' => time()];
        // Learned routes belong to a specific service origin and schema. Do
        // not carry them over when either changes; stale paths otherwise make
        // a valid connector appear broken (or, worse, target the old host).
        $previousBase = rtrim((string)($previous['base_url'] ?? ''), '/');
        $previousSchema = trim((string)($previous['openapi_url'] ?? ''));
        if (is_array($previous['openapi'] ?? null) && $previousBase === $base && $previousSchema === $schemaUrl) {
            $rows[$id]['openapi'] = $previous['openapi'];
        }
        $user = $this->config->userId() ?? '';
        Server::get(\OCP\IConfig::class)->setUserValue($user, AppConfig::APP, 'external_connectors', json_encode($rows, JSON_UNESCAPED_SLASHES) ?: '{}');
        $credentials = Server::get(ProviderCredentials::class);
        // The settings form intentionally sends empty secret fields when a
        // user edits a connector. An empty value means "keep the stored
        // secret" (matching the UI placeholder), never delete credentials.
        // Explicit credential removal can be added separately without making
        // ordinary connector edits silently break authentication.
        if (array_key_exists('token', $args) && trim((string)$args['token']) !== '') {
            $credentials->saveCustomValue($user, 'connector_' . $id, 'token', trim((string)$args['token']));
        }
        foreach (['username', 'password', 'api_key'] as $field) {
            if (array_key_exists($field, $args) && trim((string)$args[$field]) !== '') {
                $credentials->saveCustomValue($user, 'connector_' . $id, $field, trim((string)$args[$field]));
            }
        }
        return ['ok' => true, 'result' => ['id' => $id, 'name' => $name, 'base_url' => $base, 'token_configured' => $rows[$id]['token_configured']]];
    }

    /**
     * Perform a bounded connectivity probe from the Nextcloud host itself.
     * This deliberately uses the same allow-list and credentials as normal
     * connector calls, but returns only transport metadata (never a body or
     * secret) so a missing route is distinguishable from an API/auth error.
     */
    private function diagnoseExternalConnector(array $args): array {
        $id = strtolower(trim((string)($args['id'] ?? '')));
        $row = $this->connectorRows()[$id] ?? null;
        $base = is_array($row) ? rtrim((string)($row['base_url'] ?? ''), '/') : '';
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D', $id) || !is_array($row) || !$this->safeConnectorUrl($base)) {
            return ['ok' => false, 'error' => 'Connector is not configured or its host is no longer allowed.'];
        }
        $user = $this->config->userId() ?? '';
        try {
            $headers = array_merge(['Accept' => 'application/json'], $this->connectorAuthHeaders($id, $row, $user));
            $lines = [];
            foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
            $ch = curl_init($base . '/');
            $started = microtime(true);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::CONNECTOR_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CONNECTOR_CONNECT_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'EvaAi/1.0 connector-diagnostic',
            ]);
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = $errno !== 0 ? curl_error($ch) : '';
            $info = curl_getinfo($ch);
            $elapsed = (int)round((microtime(true) - $started) * 1000);
            $status = (int)($info['http_code'] ?? 0);
            $ip = (string)($info['primary_ip'] ?? '');
            $category = $errno !== 0 ? 'network' : ($status === 401 || $status === 403 ? 'authentication' : ($status >= 200 && $status < 500 ? 'reachable' : 'http'));
            $effectiveAuthType = (string)($row['auth_type'] ?? '');
            if ($effectiveAuthType === '') {
                $effectiveAuthType = !empty($row['token_configured']) ? 'bearer' : 'none';
            }
            return ['ok' => $errno === 0 && $status >= 200 && $status < 500, 'result' => [
                'connector' => $id, 'base_url' => $base, 'status' => $status,
                'resolved_ip' => $ip, 'elapsed_ms' => $elapsed, 'category' => $category,
                'auth_type' => $effectiveAuthType,
                'error' => $error !== '' ? mb_substr($error, 0, 240) : null,
                'hint' => $errno !== 0 ? 'The Nextcloud server cannot reach this host. Check routing/VPN/firewall and bind the service to a reachable address.' : null,
            ]];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Connector diagnostic failed: ' . mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private function callExternalConnector(array $args): array {
        $id = strtolower(trim((string)($args['id'] ?? ''))); $path = trim((string)($args['path'] ?? '')); $method = strtoupper(trim((string)($args['method'] ?? ''))); $params = $args['params'] ?? [];
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D', $id) || !in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) || !is_array($params) || count($params) > 50 || $path === '' || str_contains($path, '..') || preg_match('/[\r\n]/', $path)) return ['ok' => false, 'error' => 'Invalid connector request.'];
        $split = $this->splitRequestPath($path, $params);
        if ($split === null) return ['ok' => false, 'error' => 'The connector path or query string is invalid.'];
        [$path, $params] = $split;
        $row = $this->connectorRows()[$id] ?? null; if (!is_array($row) || !$this->safeConnectorUrl((string)($row['base_url'] ?? ''))) return ['ok' => false, 'error' => 'Connector is not configured or its host is no longer allowed.'];
        $knownEndpoints = is_array($row['openapi']['endpoints'] ?? null) ? $row['openapi']['endpoints'] : [];
        $matchedEndpoint = null;
        if ($knownEndpoints !== []) {
            $known = false;
            foreach ($knownEndpoints as $endpoint) {
                if (!is_array($endpoint)) continue;
                if (strtoupper((string)($endpoint['method'] ?? '')) === $method
                    && $this->matchesDiscoveredRoute((string)($endpoint['path'] ?? ''), $path)) { $known = true; $matchedEndpoint = $endpoint; break; }
            }
            if (!$known) {
                // Discovery is read-only, so refresh a stale/partial route
                // catalog transparently before rejecting a confirmed call.
                // This keeps generic connectors self-learning without
                // allowing arbitrary host/path probing: the request is still
                // retried only when discovery records the exact route.
                if (($args['_auto_discover'] ?? true) === true) {
                    $discovered = $this->discoverExternalConnector(['id' => $id]);
                    if (($discovered['ok'] ?? false) === true) {
                        $args['_auto_discover'] = false;
                        return $this->callExternalConnector($args);
                    }
                }
                return ['ok' => false, 'error' => 'This connector route was not discovered. Run discover_external_connector first.'];
            }
            if ($method !== 'GET' && is_array($matchedEndpoint)) {
                $bodyError = $this->validateConnectorRequestBody($matchedEndpoint, $params);
                if ($bodyError !== null) return ['ok' => false, 'error' => $bodyError];
            }
        }
        $pathTemplate = $path;
        $expandedPath = $this->expandConnectorPath($pathTemplate, $params);
        if ($expandedPath === null) return ['ok' => false, 'error' => 'A required connector path parameter is missing or invalid.'];
        $params = $this->removePathParameters($pathTemplate, $params);
        $path = $expandedPath;
        $url = rtrim((string)$row['base_url'], '/') . '/' . ltrim($path, '/');
        if (!$this->safeConnectorUrl($url)) return ['ok' => false, 'error' => 'Connector path leaves the configured HTTPS host.'];
        try {
            $headers = ['Accept' => 'application/json']; $user = $this->config->userId() ?? '';
            $headers = array_merge($headers, $this->connectorAuthHeaders($id, $row, $user));
            if ($method === 'GET') {
                return $this->callExternalConnectorGet($id, $path, $url, $params, $headers, $user);
            }
            // OpenAPI distinguishes query parameters from JSON body fields.
            // Preserve that distinction for learned POST/PUT/PATCH routes;
            // unknown parameters remain in the body for backwards compatibility.
            $queryParams = [];
            if (is_array($matchedEndpoint['parameters'] ?? null)) {
                foreach ($matchedEndpoint['parameters'] as $parameter) {
                    if (!is_array($parameter) || ($parameter['in'] ?? '') !== 'query') continue;
                    $name = (string)($parameter['name'] ?? '');
                    if ($name !== '' && array_key_exists($name, $params)) {
                        $queryParams[$name] = $params[$name];
                        unset($params[$name]);
                    }
                }
            }
            if ($queryParams !== []) $url .= '?' . http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
            $contentType = strtolower(trim((string)($matchedEndpoint['request_body']['content_type'] ?? 'application/json')));
            if (str_contains($contentType, ';')) $contentType = trim((string)explode(';', $contentType, 2)[0]);
            if (!in_array($contentType, ['application/json', 'application/x-www-form-urlencoded', 'multipart/form-data'], true)) $contentType = 'application/json';
            // cURL builds multipart boundaries itself; manually setting that
            // header would omit the boundary and break otherwise valid APIs.
            if ($contentType !== 'multipart/form-data') $headers['Content-Type'] = $contentType;
            [$status, $body, $transportError] = $this->connectorCurlRequest($url, $method, $headers, $params, self::CONNECTOR_TIMEOUT, $contentType);
            if ($transportError !== '') return ['ok' => false, 'error' => 'External connector request failed. ' . $transportError];
            $body = mb_substr($body, 0, 50000); $data = json_decode($body, true); $safeData = is_array($data) ? $this->redactApiPayload($data) : $body;
            if ($status >= 200 && $status < 300) {
                $rows = $this->connectorRows(); $known = $rows[$id]['openapi']['endpoints'] ?? []; if (!is_array($known)) $known = [];
                $seen = false; foreach ($known as $entry) if (is_array($entry) && strtoupper((string)($entry['method'] ?? '')) === $method && (string)($entry['path'] ?? '') === $path) { $seen = true; break; }
                if (!$seen) { $known[] = ['path' => mb_substr($path, 0, 300), 'method' => $method, 'operation_id' => 'learned']; $rows[$id]['openapi'] = ['source' => $rows[$id]['openapi']['source'] ?? 'runtime', 'version' => $rows[$id]['openapi']['version'] ?? '', 'endpoints' => array_slice($known, -200), 'updated_at' => time()]; Server::get(\OCP\IConfig::class)->setUserValue($user, AppConfig::APP, 'external_connectors', json_encode($rows, JSON_UNESCAPED_SLASHES) ?: '{}'); }
            }
            return ['ok' => $status >= 200 && $status < 300, 'result' => ['status' => $status, 'data' => $safeData, 'connector' => $id, 'method' => $method, 'path' => $path, 'attempts' => 1]];
        } catch (\Throwable $e) {
            $detail = trim(preg_replace('/\s+/', ' ', $e->getMessage()));
            return ['ok' => false, 'error' => 'External connector request failed.' . ($detail !== '' ? ' ' . mb_substr($detail, 0, 220) : '')];
        }
    }

    /** GET adapter used by tests and read-only calls; cURL preserves HTTP
     * status responses (401/404) instead of turning them into client errors. */
    private function callExternalConnectorGet(string $id, string $path, string $url, array $params, array $headers, string $user): array {
        if ($params !== []) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        [$status, $body, $attempts] = $this->connectorCurlGet($url, $headers, self::CONNECTOR_TIMEOUT);
        if ($status === 0) return ['ok' => false, 'error' => 'External connector is unreachable from the Nextcloud server.'];
        $body = mb_substr($body, 0, 50000); $data = json_decode($body, true); $safeData = is_array($data) ? $this->redactApiPayload($data) : $body;
        if ($status >= 200 && $status < 300) {
            $rows = $this->connectorRows(); $known = $rows[$id]['openapi']['endpoints'] ?? []; if (!is_array($known)) $known = [];
            $seen = false; foreach ($known as $entry) if (is_array($entry) && strtoupper((string)($entry['method'] ?? '')) === 'GET' && (string)($entry['path'] ?? '') === $path) { $seen = true; break; }
            if (!$seen) { $known[] = ['path' => mb_substr($path, 0, 300), 'method' => 'GET', 'operation_id' => 'learned']; $rows[$id]['openapi'] = ['source' => $rows[$id]['openapi']['source'] ?? 'runtime', 'version' => $rows[$id]['openapi']['version'] ?? '', 'endpoints' => array_slice($known, -200), 'updated_at' => time()]; Server::get(\OCP\IConfig::class)->setUserValue($user, AppConfig::APP, 'external_connectors', json_encode($rows, JSON_UNESCAPED_SLASHES) ?: '{}'); }
        }
        return ['ok' => $status >= 200 && $status < 300, 'result' => ['status' => $status, 'data' => $safeData, 'connector' => $id, 'method' => 'GET', 'path' => $path, 'attempts' => $attempts]];
    }

    private function safeConnectorUrl(string $url): bool {
        $parts = parse_url($url); $host = strtolower((string)($parts['host'] ?? '')); $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) return false;
        // parse_url() retains brackets around IPv6 literals; remove them only
        // for validation while preserving the original URL for curl.
        $validationHost = trim($host, '[]');
        $isIp = filter_var($validationHost, FILTER_VALIDATE_IP) !== false;
        if (!$isIp && filter_var($validationHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) return false;
        $ip = $isIp ? $validationHost : gethostbyname($validationHost);
        $isLocalName = $validationHost === 'localhost' || str_ends_with($validationHost, '.local') || str_ends_with($validationHost, '.lan');
        $isPrivateIp = filter_var($ip, FILTER_VALIDATE_IP) !== false
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            && !str_starts_with($ip, '169.254.');
        $local = $isLocalName || $isPrivateIp;
        if ($scheme === 'http' && !$local) return false;
        if ($local) return $isLocalName || $isPrivateIp;
        // For hostnames gethostbyname() must resolve (the `$ip !== $host`
        // condition); literal public IP addresses are already validated above.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function sameConnectorHost(string $candidate, string $base): bool {
        $candidateParts = parse_url($candidate);
        $baseParts = parse_url($base);
        if (!is_array($candidateParts) || !is_array($baseParts)) return false;
        return strtolower((string)($candidateParts['host'] ?? '')) === strtolower((string)($baseParts['host'] ?? ''))
            && (int)($candidateParts['port'] ?? (($candidateParts['scheme'] ?? '') === 'https' ? 443 : 80)) === (int)($baseParts['port'] ?? (($baseParts['scheme'] ?? '') === 'https' ? 443 : 80));
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

    /** Validate only fields explicitly marked required by a learned schema. */
    private function validateConnectorRequestBody(array $endpoint, array $params): ?string {
        $body = $endpoint['request_body'] ?? null;
        if (!is_array($body) || empty($body['required']) || !is_array($body['fields'] ?? null)) return null;
        foreach ($body['fields'] as $field) {
            if (!is_array($field) || empty($field['required'])) continue;
            $name = (string)($field['name'] ?? '');
            if ($name === '' || !array_key_exists($name, $params) || $params[$name] === '' || $params[$name] === null) {
                return 'The discovered connector schema requires JSON field: ' . $name;
            }
        }
        return null;
    }

    /**
     * Accept both a clean path plus params and the common `/route?key=value`
     * form emitted by models. Query values are merged without overwriting
     * explicit structured params, so discovered-route matching always sees
     * the actual route rather than its query string.
     *
     * @return array{0:string,1:array}|null
     */
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

    /** Build connector auth headers without ever returning credential values. */
    private function connectorAuthHeaders(string $id, array $row, string $user): array {
        $type = (string)($row['auth_type'] ?? (!empty($row['token_configured']) ? 'bearer' : 'none'));
        $credentials = Server::get(ProviderCredentials::class); $prefix = 'connector_' . $id;
        try {
            if ($type === 'basic' && !empty($row['username_configured']) && !empty($row['password_configured'])) {
                return ['Authorization' => 'Basic ' . base64_encode($credentials->getCustomValue($user, $prefix, 'username') . ':' . $credentials->getCustomValue($user, $prefix, 'password'))];
            }
            if ($type === 'api_key' && !empty($row['api_key_configured'])) {
                return [$this->normalizedApiKeyHeader($row) => $credentials->getCustomValue($user, $prefix, 'api_key')];
            }
            if ($type === 'bearer') {
                if ($credentials->customValueConfigured($user, $prefix, 'token')) {
                    return ['Authorization' => 'Bearer ' . $this->normalizeBearerToken($credentials->getCustomValue($user, $prefix, 'token'))];
                }
                if ($credentials->customValueConfigured($user, $prefix, 'api_key')) {
                    return ['Authorization' => 'Bearer ' . $this->normalizeBearerToken($credentials->getCustomValue($user, $prefix, 'api_key'))];
                }
            }
        } catch (\Throwable) { return []; }
        return [];
    }

    /**
     * Users commonly paste the complete header value ("Bearer xxx") into a
     * token field. Do not send a malformed double prefix to TrueNAS or other
     * RFC 6750 services; only the scheme prefix is removed, never token data.
     */
    private function normalizeBearerToken(string $value): string {
        $value = trim($value);
        return preg_replace('/^(?:Bearer|Token)\s+/i', '', $value) ?? $value;
    }

    /** Prevent an API secret accidentally being used as the header name. */
    private function normalizedApiKeyHeader(array $row): string {
        $header = trim((string)($row['api_key_header'] ?? 'X-API-Key'));
        if (preg_match('/^[A-Za-z][A-Za-z0-9-]{0,59}$/D', $header) !== 1) return 'X-API-Key';
        // Long, delimiter-free values are characteristic of pasted secrets,
        // not HTTP header names. Recover the documented default automatically.
        if (strlen($header) > 32 && !str_contains($header, '-')) return 'X-API-Key';
        return $header;
    }

/** @return array{0:int,1:string,2:int} */
    private function connectorCurlGet(string $url, array $headers, int $timeout): array {
        $lines = [];
        foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
        $status = 0; $body = '';
        for ($attempt = 1; $attempt <= self::CONNECTOR_GET_ATTEMPTS; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => self::CONNECTOR_CONNECT_TIMEOUT, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $lines, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
            $result = curl_exec($ch); $error = curl_errno($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = is_string($result) ? $result : '';
            if ($error === 0 && !in_array($status, [408, 425, 429], true) && ($status < 500 || $status >= 600)) break;
            if ($attempt < self::CONNECTOR_GET_ATTEMPTS) usleep(100000 * $attempt);
        }
        return [$error === 0 ? $status : 0, $body, $attempt];
    }

    /** @return array{0:int,1:string,2:string} */
    private function connectorCurlRequest(string $url, string $method, array $headers, array $params, int $timeout, string $contentType = 'application/json'): array {
        $lines = [];
        foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
        try {
            $payload = match ($contentType) {
                'application/x-www-form-urlencoded' => http_build_query($params, '', '&', PHP_QUERY_RFC3986),
                'multipart/form-data' => $params,
                default => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            };
        } catch (\Throwable) {
            return [0, '', 'Request parameters could not be encoded as JSON.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => self::CONNECTOR_CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'EvaAi/1.0 connector',
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno !== 0 ? curl_error($ch) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$errno === 0 ? $status : 0, is_string($body) ? $body : '', mb_substr($error, 0, 220)];
    }
}
