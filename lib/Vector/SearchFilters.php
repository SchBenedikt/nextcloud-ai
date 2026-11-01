<?php

declare(strict_types=1);

namespace OCA\EvaAi\Vector;

/** No tenant or generation override: both always come from server-owned identity. */
final readonly class SearchFilters {
    public function __construct(public ?string $scopePath = null, public ?string $source = null, public array $documentIds = []) {
        if ($scopePath !== null && (strlen($scopePath) > 4096 || trim($scopePath, '/') !== $scopePath || preg_match('/[\x00-\x1f\x7f]/', $scopePath)
            || str_contains($scopePath, '\\') || str_contains($scopePath, '//') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $scopePath))) {
            throw new \InvalidArgumentException('Invalid search scope path.');
        }
        if ($source !== null && !in_array($source, ['files', 'mail', 'talk'], true)) throw new \InvalidArgumentException('Invalid search source.');
        if (!array_is_list($documentIds) || count($documentIds) > 500) throw new \InvalidArgumentException('Too many document filters.');
        foreach ($documentIds as $id) if (!is_int($id) || $id < 1) throw new \InvalidArgumentException('Invalid document filter.');
    }
}
