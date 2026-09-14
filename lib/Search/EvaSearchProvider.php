<?php

declare(strict_types=1);

namespace OCA\EvaAi\Search;

use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\Document;
use OCA\EvaAi\Db\DocumentMapper;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Makes EVA's indexed file content available in Nextcloud Unified Search.
 *
 * This deliberately stays lexical and bounded: opening the global search box
 * must not start an Ollama request. The current VFS node is checked before a
 * result is returned because an index row can outlive a permission change.
 */
final class EvaSearchProvider implements IProvider {
    private const MAX_RESULTS = 25;
    private const MAX_CANDIDATES = 200;

    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $urlGenerator,
        private IRootFolder $rootFolder,
        private DocumentMapper $documentMapper,
        private ChunkMapper $chunkMapper,
    ) {
    }

    public function getId(): string {
        return 'eva_ai';
    }

    public function getName(): string {
        return $this->l10n->t('EVA indexed files');
    }

    public function getOrder(string $route, array $routeParameters): int {
        if (str_starts_with($route, 'eva_ai.')) {
            return -1;
        }
        if (str_starts_with($route, 'files.')) {
            return 15;
        }
        return 10;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $term = trim($query->getTerm());
        $name = $this->getName();
        if ($term === '') {
            return SearchResult::complete($name, []);
        }

        $limit = max(1, min(self::MAX_RESULTS, $query->getLimit() > 0 ? $query->getLimit() : self::MAX_RESULTS));
        $uid = $user->getUID();
        $documents = [];

        // Path/name matches are cheap and useful even when a document has no
        // searchable text chunk (for example a binary file with metadata).
        $pathTerm = trim(str_replace(['%', '_'], ' ', mb_substr($term, 0, 120)));
        if ($pathTerm !== '') {
            foreach ($this->documentMapper->findByUser($uid, $pathTerm, self::MAX_CANDIDATES, 0) as $document) {
                if ($this->isSearchableFile($document)) {
                    $documents[$document->getId()] = ['document' => $document, 'excerpt' => ''];
                }
            }
        }

        // Content matches use the same bounded token prefilter as RAG, but
        // never deserialize embeddings or call an inference provider.
        $tokens = $this->tokens($term);
        if ($tokens !== []) {
            $chunks = $this->chunkMapper->filterChunksByTokens(
                $uid,
                $tokens,
                self::MAX_CANDIDATES,
                Document::SOURCE_FILES,
            );
            $documentIds = [];
            foreach ($chunks as $chunk) {
                $id = (int)($chunk['document_id'] ?? 0);
                if ($id > 0) {
                    $documentIds[$id] = true;
                }
            }
            foreach ($this->documentMapper->findByIds(array_keys($documentIds)) as $document) {
                if (!$this->isSearchableFile($document) || isset($documents[$document->getId()])) {
                    continue;
                }
                $documents[$document->getId()] = ['document' => $document, 'excerpt' => ''];
            }
            foreach ($chunks as $chunk) {
                $id = (int)($chunk['document_id'] ?? 0);
                if ($id <= 0 || !isset($documents[$id]) || $documents[$id]['excerpt'] !== '') {
                    continue;
                }
                $documents[$id]['excerpt'] = $this->excerpt((string)($chunk['content'] ?? ''), $term);
            }
        }

        $entries = [];
        foreach (array_slice(array_values($documents), 0, $limit) as $row) {
            $document = $row['document'];
            $fileId = (int)$document->getFileId();
            if ($fileId <= 0 || !$this->isCurrentlyAccessible($uid, $fileId)) {
                continue;
            }
            $link = $this->urlGenerator->linkToRouteAbsolute('files.View.showFile', ['fileid' => $fileId]);
            $subline = (string)$document->getPath();
            if ($row['excerpt'] !== '') {
                $subline .= ' — ' . $row['excerpt'];
            }
            $entry = new SearchResultEntry(
                '',
                (string)$document->getName(),
                mb_substr($subline, 0, 400),
                $link,
                'icon-file',
            );
            $entry->addAttribute('fileId', (string)$fileId);
            $entry->addAttribute('path', (string)$document->getPath());
            $entries[] = $entry;
            if (count($entries) >= $limit) {
                break;
            }
        }

        return SearchResult::paginated($name, $entries, (int)($query->getCursor() ?? 0) + count($entries));
    }

    /** @return list<string> */
    private function tokens(string $term): array {
        $parts = preg_split('/[^\p{L}\p{N}_-]+/u', mb_strtolower($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = array_map(static fn(string $part): string => str_replace(['%', '_'], '', $part), $parts);
        $parts = array_values(array_unique(array_filter($parts, static fn(string $part): bool => mb_strlen($part) >= 2)));
        return array_slice($parts, 0, 12);
    }

    private function excerpt(string $content, string $term): string {
        $content = trim((string)preg_replace('/\s+/u', ' ', $content));
        if ($content === '') {
            return '';
        }
        $needle = mb_strtolower($term);
        $position = mb_stripos($content, $needle);
        if ($position === false) {
            foreach ($this->tokens($term) as $token) {
                $position = mb_stripos($content, $token);
                if ($position !== false) {
                    break;
                }
            }
        }
        $start = $position === false ? 0 : max(0, $position - 70);
        $excerpt = mb_substr($content, $start, 220);
        return ($start > 0 ? '…' : '') . $excerpt . (mb_strlen($content) > $start + 220 ? '…' : '');
    }

    private function isSearchableFile(Document $document): bool {
        return $document->getSource() === Document::SOURCE_FILES && (int)$document->getFileId() > 0;
    }

    private function isCurrentlyAccessible(string $userId, int $fileId): bool {
        try {
            $folder = $this->rootFolder->getUserFolder($userId);
            $nodes = $folder->getById($fileId);
            return is_array($nodes) && $nodes !== [];
        } catch (\Throwable) {
            return false;
        }
    }
}
