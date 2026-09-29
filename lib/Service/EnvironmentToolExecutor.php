<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Files\IRootFolder;

/** Handles time, status, weather, and public web discovery tools. */
final class EnvironmentToolExecutor implements DomainToolExecutor {
    public function __construct(private IRootFolder $rootFolder, private AppConfig $config, private WebSearchService $webSearch) {
    }

    public function tools(): array {
        return ['current_time', 'server_status', 'weather', 'web_search', 'search_images', 'open_website'];
    }

    public function execute(string $tool, string $userId, array $args): array {
        return match ($tool) {
            'current_time' => $this->currentTime($userId),
            'server_status' => $this->serverStatus($userId),
            'weather' => $this->weather($args),
            'web_search' => $this->runWebSearch($args),
            'search_images' => $this->runImageSearch($args),
            'open_website' => $this->openWebsite($args),
            default => ['ok' => false, 'error' => 'Unsupported environment tool: ' . $tool],
        };
    }

    private function currentTime(string $userId): array {
        $tz = 'Europe/Berlin';
        try {
            $tz = \OCP\Server::get(\OCP\IConfig::class)->getUserValue($userId, 'core', 'timezone', 'Europe/Berlin');
        } catch (\Throwable $e) {
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone($tz));
        $names = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
        return [
            'ok' => true,
            'result' => [
                'datetime' => $now->format('Y-m-d H:i:s'),
                'date' => $now->format('Y-m-d'),
                'time' => $now->format('H:i'),
                'weekday' => $names[(int)$now->format('w')],
                'iso8601' => $now->format('c'),
                'timezone' => $tz,
                'unix' => $now->getTimestamp(),
            ],
        ];
    }

    private function serverStatus(string $userId): array {
        $version = implode('.', \OCP\Util::getVersion());
        $quota = null;
        try {
            $home = $this->rootFolder->getUserFolder($userId);
            $quota = ['free_bytes' => $home->getFreeSpace(), 'used_bytes' => (int)$home->getSize()];
        } catch (\Throwable $e) {
        }
        $dbName = '';
        try {
            $dbName = \OC::$server->get(\OC\SystemConfig::class)->getValue('dbtype', 'sqlite');
        } catch (\Throwable $e) {
        }
        return ['ok' => true, 'result' => [
            'user' => $userId,
            'nextcloud' => $version,
            'php' => PHP_VERSION,
            'database' => $dbName,
            'ollama_url' => $this->config->get('ollama_url'),
            'chat_model' => $this->config->get('chat_model'),
            'embedding_model' => $this->config->get('embedding_model'),
            'quota' => $quota,
            'mail_index_enabled' => $this->config->get('mail_index_enabled') === '1',
        ]];
    }

    private function runImageSearch(array $args): array {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            return ['ok' => false, 'error' => 'query required'];
        }
        $count = isset($args['count']) ? (int)$args['count'] : null;
        $result = $this->webSearch->searchImages($query, $count);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => (string)($result['error'] ?? 'The image search failed.')];
        }
        return [
            'ok' => true,
            'result' => [
                'query' => $query,
                'provider' => $result['provider'],
                // `external: true` marks these as links outside the Nextcloud
                // instance so callers never confuse them with indexed files.
                'external' => true,
                'images' => array_map(static fn(array $image): array => [
                    'url' => $image['url'],
                    'preview' => $image['preview'],
                    'title' => $image['title'],
                    'page' => $image['page'],
                ], $result['images']),
                'markdown' => implode("\n", array_map(static fn(array $image): string =>
                    '![' . str_replace([']', '['], '', (string)$image['title']) . '](' . (string)$image['url'] . ')',
                    array_slice($result['images'], 0, 4)
                )),
            ],
        ];
    }

    /**
     * Read one page in full for the model.
     *
     * Search results only carry a bounded excerpt, so a detail that sits deeper
     * in a page (a figure, a date, a quotation) would otherwise be guessed at.
     * The URL is validated by the service, so a tool call can never make the
     * server fetch an internal or non-http address.
     */
    private function openWebsite(array $args): array {
        $url = trim((string)($args['url'] ?? ''));
        if ($url === '') {
            return ['ok' => false, 'error' => 'url required'];
        }
        $query = trim((string)($args['query'] ?? ''));
        $offset = max(0, (int)($args['offset'] ?? 0));
        $maxChars = isset($args['max_chars']) ? (int)$args['max_chars'] : null;
        $page = $this->webSearch->openPage($url, $query, $offset, $maxChars);
        if (!$page['ok']) {
            return ['ok' => false, 'error' => (string)($page['error'] ?? 'The page could not be opened.')];
        }
        return [
            'ok' => true,
            'result' => [
                'url' => $page['url'],
                'title' => $page['title'],
                'external' => true,
                'published' => $page['published'],
                'truncated' => $page['truncated'],
                'offset' => $page['offset'],
                'next_offset' => $page['next_offset'],
                'total_chars' => $page['total_chars'],
                'has_more' => $page['has_more'],
                'highlights' => $page['highlights'],
                'images' => $page['images'],
                'text' => $page['text'],
            ],
        ];
    }

    /**
     * The user's Talk rooms, so the model can pick the right one by name.
     */
    private function runWebSearch(array $args): array {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            return ['ok' => false, 'error' => 'query required'];
        }
        $mode = trim((string)($args['mode'] ?? 'web'));
        if (!in_array($mode, WebSearchService::MODES, true)) {
            $mode = 'web';
        }
        $result = $this->webSearch->search($query, null, $mode);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => (string)($result['error'] ?? 'Web search failed.')];
        }
        // The service already ranks and bounds the list; this only guards the
        // tool result against a misconfigured limit.
        $results = array_slice($result['results'], 0, 20);
        if ($results === []) {
            return ['ok' => true, 'result' => ['query' => $query, 'provider' => $result['provider'], 'results' => []]];
        }
        return [
            'ok' => true,
            'result' => [
                'query' => $query,
                'mode' => $result['mode'] ?? $mode,
                'provider' => $result['provider'],
                // `external: true` marks these as links outside the Nextcloud
                // instance so callers never confuse them with indexed files.
                'external' => true,
                'results' => $results,
            ],
        ];
    }

    private function weather(array $args): array {
        $loc = trim((string)($args['location'] ?? ''));
        if ($loc === '') {
            return ['ok' => false, 'error' => 'location required'];
        }
        $geo = $this->httpGet('https://geocoding-api.open-meteo.com/v1/search?count=1&language=de&format=json&name=' . rawurlencode($loc));
        if ($geo === null) {
            return ['ok' => false, 'error' => 'Weather service unreachable.'];
        }
        $g = json_decode($geo, true);
        $lat = $g['results'][0]['latitude'] ?? null;
        $lon = $g['results'][0]['longitude'] ?? null;
        $name = (string)($g['results'][0]['name'] ?? $loc);
        if ($lat === null || $lon === null) {
            return ['ok' => false, 'error' => 'Place not found: ' . $loc];
        }
        $f = $this->httpGet('https://api.open-meteo.com/v1/forecast?latitude=' . $lat . '&longitude=' . $lon . '&daily=temperature_2m_max,temperature_2m_min,weathercode&forecast_days=3&timezone=auto');
        if ($f === null) {
            return ['ok' => false, 'error' => 'Weather service unreachable.'];
        }
        $j = json_decode($f, true);
        $codes = [
            0 => 'Clear', 1 => 'Mostly clear', 2 => 'Partly cloudy', 3 => 'Overcast',
            45 => 'Fog', 48 => 'Rime fog',
            51 => 'Light drizzle', 53 => 'Drizzle', 55 => 'Heavy drizzle',
            61 => 'Light rain', 63 => 'Rain', 65 => 'Heavy rain',
            71 => 'Light snow', 73 => 'Snow', 75 => 'Heavy snow',
            80 => 'Light showers', 81 => 'Showers', 82 => 'Heavy showers',
            95 => 'Thunderstorm', 96 => 'Thunderstorm with hail', 99 => 'Thunderstorm with hail',
        ];
        $days = [];
        foreach (($j['daily']['time'] ?? []) as $i => $day) {
            $code = (int)($j['daily']['weathercode'][$i] ?? 0);
            $days[] = [
                'date' => (string)$day,
                'forecast' => (string)($codes[$code] ?? 'Unknown'),
                'max' => ($j['daily']['temperature_2m_max'][$i] ?? null),
                'min' => ($j['daily']['temperature_2m_min'][$i] ?? null),
            ];
        }
        return ['ok' => true, 'result' => ['location' => $name, 'days' => $days]];
    }

    private function httpGet(string $url, int $timeout = 8): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'EvaAi/1.0',
        ]);
        $r = curl_exec($ch);
        $err = curl_errno($ch);
        // No curl_close(): the handle is freed automatically (PHP 8.0+) and the
        // function is deprecated in PHP 8.5.
        return $err === 0 && is_string($r) && $r !== '' ? $r : null;
    }

}
