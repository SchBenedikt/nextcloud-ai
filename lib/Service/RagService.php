<?php

declare(strict_types=1);

namespace OCA\RagChat\Service;

use OCA\RagChat\Db\ChunkMapper;
use OCA\RagChat\Db\DocumentMapper;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;

class RagService {
    private const MAX_TOOL_ROUNDS = 4;

    public function __construct(
        private AppConfig $config,
        private Ollama $ollama,
        private Searcher $searcher,
        private DocumentMapper $documentMapper,
        private ChunkMapper $chunkMapper,
        private IURLGenerator $urlGenerator,
        private ActionExecutor $executor,
        private IRootFolder $rootFolder
    ) {
    }

    /**
     * @param array<int,array{role:string,content:string}> $history
     * @return array{answer:string,sources:array,model:string,error:?string}
     */
    public function ask(string $userId, string $message, array $history): array {
        $topK = min($this->config->getInt('top_k', 6), 8);
        $results = $this->searcher->search($userId, $this->searchQuery($message, $history), $topK);

        [$context, $byDoc] = $this->buildContext($userId, $results);

        $tools = $this->actionsEnabled() ? $this->executor->tools() : [];
        $messages = $this->buildMessages($userId, $message, $history, $context, count($results), $tools !== []);

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $chat = $this->ollama->chat($messages, $tools);
            if (isset($chat['error'])) {
                return ['answer' => '', 'sources' => array_values($byDoc), 'model' => $this->config->get('chat_model'), 'error' => $chat['error']];
            }
            $toolCalls = $chat['tool_calls'] ?? [];
            if ($toolCalls === []) {
                return [
                    'answer' => $chat['answer'] ?? '',
                    'sources' => array_values($byDoc),
                    'model' => $chat['model'] ?? $this->config->get('chat_model'),
                    'error' => null,
                ];
            }
            $messages[] = ['role' => 'assistant', 'content' => $chat['answer'] ?? '', 'tool_calls' => $this->canonicalToolCalls($chat['raw_tool_calls'] ?? [])];
            foreach ($toolCalls as $tc) {
                $res = $this->executor->run($userId, $tc['name'], $tc['arguments']);
                $messages[] = ['role' => 'tool', 'content' => json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            }
        }

        return [
            'answer' => '',
            'sources' => array_values($byDoc),
            'model' => $this->config->get('chat_model'),
            'error' => 'Maximale Anzahl an Tool-Schritten erreicht.',
        ];
    }

    /**
     * Streaming variant: yields NDJSON line strings for the browser.
     * @param array<int,array{role:string,content:string}> $history
     * @return \Generator<string,string,void,void>
     */
    public function askStream(string $userId, string $message, array $history): \Generator {
        try {
            if (trim($message) === '') {
                yield json_encode(['type' => 'error', 'message' => 'Empty message']) . "\n";
                return;
            }
            $topK = min($this->config->getInt('top_k', 6), 8);
            $results = $this->searcher->search($userId, $this->searchQuery($message, $history), $topK);
            [$context, $byDoc] = $this->buildContext($userId, $results);

            $tools = $this->actionsEnabled() ? $this->executor->tools() : [];
            $messages = $this->buildMessages($userId, $message, $history, $context, count($results), $tools !== []);

            $answer = '';
            $model = $this->config->get('chat_model');
            for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
                $toolCalls = [];
                $rawToolCalls = [];
                foreach ($this->ollama->chatStream($messages, $tools) as $ev) {
                    $evType = $ev['type'] ?? '';
                    if ($evType === 'content') {
                        $answer .= $ev['delta'] ?? '';
                        yield json_encode(['type' => 'content', 'delta' => $ev['delta'] ?? '']) . "\n";
                    } elseif ($evType === 'thinking') {
                        yield json_encode(['type' => 'thinking', 'delta' => $ev['delta'] ?? '']) . "\n";
                    } elseif ($evType === 'tool_calls') {
                        $toolCalls = $ev['tool_calls'] ?? [];
                        $rawToolCalls = $ev['raw'] ?? [];
                    } elseif ($evType === 'error') {
                        yield json_encode(['type' => 'error', 'message' => $ev['delta'] ?? 'Ollama error']) . "\n";
                        return;
                    }
                }
                if ($toolCalls === []) {
                    break;
                }
                $messages[] = ['role' => 'assistant', 'content' => $answer, 'tool_calls' => $this->canonicalToolCalls($rawToolCalls)];
                foreach ($toolCalls as $tc) {
                    yield json_encode(['type' => 'tool', 'name' => $tc['name'] ?? '?']) . "\n";
                    $res = $this->executor->run($userId, $tc['name'] ?? '', $tc['arguments'] ?? []);
                    yield json_encode(['type' => 'tool_result', 'name' => $tc['name'] ?? '?', 'ok' => !empty($res['ok']), 'error' => $res['error'] ?? null]) . "\n";
                    $messages[] = ['role' => 'tool', 'content' => json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
                }
                $answer = '';
            }
            if ($answer === '') {
                yield json_encode(['type' => 'error', 'message' => 'No text response received from Ollama.']) . "\n";
                return;
            }
            yield json_encode([
                'type' => 'done',
                'answer' => $answer,
                'model' => $model,
                'sources' => array_values($byDoc),
            ]) . "\n";
        } catch (\Throwable $e) {
            yield json_encode(['type' => 'error', 'message' => 'Ollama error: ' . $e->getMessage()]) . "\n";
        }
    }

    /**
     * Makes follow-up questions ("und was bringt das?") find relevant chunks:
     * the previous assistant answer is included in the retrieval query.
     * @param array<int,array{role:string,content:string}> $history
     */
    private function searchQuery(string $message, array $history): string {
        $prev = '';
        foreach (array_slice($history, -2) as $h) {
            if (($h['role'] ?? '') === 'assistant') {
                $prev = (string)($h['content'] ?? '');
            }
        }
        if ($prev === '') {
            return $message;
        }
        return mb_substr($prev, 0, 500) . "\n\nUser question: " . $message;
    }

    /**
     * @return array{0:string,1:array}
     */
    private function buildContext(string $userId, array $results): array {
        $context = '';
        $byDoc = [];
        foreach ($results as $i => $r) {
            $idx = $i + 1;
            $context .= "[{$idx}] (Source: {$r['docPath']})\n{$r['content']}\n\n";
            $docId = $r['documentId'];
            if (!isset($byDoc[$docId])) {
                $byDoc[$docId] = [
                    'path' => $r['docPath'],
                    'name' => $r['docName'],
                    'url' => $this->fileUrl($userId, $r['docPath']),
                    'excerpts' => [],
                ];
            }
            $byDoc[$docId]['excerpts'][] = mb_substr($r['content'], 0, 300);
        }
        return [$context, $byDoc];
    }

    /**
     * @param array<int,array{role:string,content:string}> $history
     * @return array<int,array{role:string,content:string}>
     */
    private function buildMessages(string $userId, string $message, array $history, string $context, int $sourceCount, bool $actions = false): array {
        $sourceCount = max(1, $sourceCount);
        $knowledge = $this->knowledgeFor($userId);
        $system = "You are a helpful, direct and precise assistant. "
            . "Answer the user's question plainly and completely, from the top, using your own knowledge whenever possible. "
            . "The user's own files are provided below as supporting context: use them when they add relevant, specific facts about the user, "
            . "The context below contains exactly {$sourceCount} numbered snippets, labelled [1] through [{$sourceCount}]. " . "Cite only with labels that really exist in that range (never invent higher numbers such as [12] or [20]). "
            . "Use at most 3-5 citations in total, only when a fact really came from a specific snippet. "
            . "Never let the context block a direct answer: if the files do not contain the answer, just answer from your general knowledge without citations. "
            . "Never write hedging openers like 'Based on the provided context, X is not defined' — instead give the definition right away. "
            . "Don't summarize what the files are about; answer the actual question. "
            . "Use standard Markdown and answer in the same language as the user's question."
            . ($knowledge !== '' ? " A file KNOWLEDGE.md holds personal facts about the user that were learned over time. Always take them into account (they override generic assumptions) and personalise your answers accordingly. Knowledge so far:\n\n" . $knowledge : "")
            . ($actions
                ? " You also have tools that work on the user's Nextcloud files: create, read, rename, delete, search and list files, manage notes and contacts. Use them when the user asks to create, save, reorganize or look up files. Run the tool, then briefly confirm what you did and where. Never use tools for anything else."
                : "");

        $userPrompt = "Context from the user's files:\n\n" . $context
            . "\n\nUser question: " . $message;

        $messages = [['role' => 'system', 'content' => $system]];
        foreach (array_slice($history, -12) as $h) {
            if (isset($h['role'], $h['content'])) {
                $messages[] = ['role' => $h['role'] === 'user' ? 'user' : 'assistant', 'content' => (string)$h['content']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $userPrompt];
        return $messages;
    }

    /** Liefert den Inhalt der persönlichen KNOWLEDGE.md (max 2500 Zeichen) oder ''. */
    private function knowledgeFor(string $userId): string {
        try {
            $home = $this->rootFolder->getUserFolder($userId);
            if (!$home->nodeExists('KNOWLEDGE.md')) {
                return '';
            }
            $node = $home->get('KNOWLEDGE.md');
            if (!$node instanceof \OCP\Files\File) {
                return '';
            }
            $content = trim((string)$node->getContent());
            if ($content === '') {
                return '';
            }
            return mb_substr($content, 0, 2500);
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function fileUrl(string $userId, string $path): string {
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
        return $this->urlGenerator->getAbsoluteURL('/remote.php/dav/files/' . rawurlencode($userId) . '/' . $encoded);
    }

    /**
     * Bringt Tool-Calls aus Modell-Antworten (Stream und Non-Stream) in die
     * von Ollama erwartete Kanonik. Ollama rechnet bei function.arguments mit
     * einem JSON-Objekt ab; ein leeres Array [] oder String wird mit 400
     * "Value looks like object, but can't find closing '}' symbol" abgelehnt.
     * @param array<int,array<string,mixed>> $raw
     * @return array<int,array{id?:string,type:string,function:array{name:string,arguments:object}}>
     */
    private function canonicalToolCalls(array $raw): array {
        $out = [];
        foreach ($raw as $tc) {
            $fn = $tc['function'] ?? $tc;
            $name = (string)($fn['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $args = $fn['arguments'] ?? '';
            if (is_string($args)) {
                $decoded = json_decode($args, true);
                $args = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($args)) {
                $args = [];
            }
            $obj = new \stdClass();
            foreach ($args as $k => $v) {
                $obj->{$k} = $v;
            }
            $out[] = [
                'id' => (string)($tc['id'] ?? ('call_' . bin2hex(random_bytes(4)))),
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'arguments' => $obj,
                ],
            ];
        }
        return $out;
    }

    private function actionsEnabled(): bool {
        return $this->config->get('actions_enabled') === '1';
    }

    public function buildStatus(string $userId): array {
        $ping = $this->ollama->ping();
        $models = $this->ollama->listModels();
        $docCount = $this->documentMapper->countForUser($userId);
        $chunkCount = $this->chunkMapper->countForUser($userId);

        $running = $this->config->get('index_running') === '1';
        if ($running) {
            $started = (int)$this->config->get('index_started');
            if (time() - $started > 3600) {
                $running = false;
            }
        }

        return [
            'enabled' => true,
            'ollamaOnline' => (bool)($ping['ok'] ?? false),
            'ollamaError' => $ping['error'] ?? null,
            'ollamaUrl' => $this->config->ollamaUrl(),
            'models' => array_map(static fn($m) => $m['name'] ?? '', $models),
            'embeddingModel' => $this->config->get('embedding_model'),
            'chatModel' => $this->config->get('chat_model'),
            'chatModelInstalled' => in_array($this->config->get('chat_model'), array_map(static fn($m) => $m['name'] ?? '', $models), true),
            'indexUser' => $this->config->get('index_user'),
            'documents' => $docCount,
            'chunks' => $chunkCount,
            'indexing' => $running,
            'lastStarted' => $this->config->get('index_started'),
            'lastFinished' => $this->config->get('index_finished'),
            'lastProcessed' => $this->config->get('last_index_processed'),
            'lastTotal' => $this->config->get('last_index_total'),
            'lastError' => $this->config->get('last_index_error'),
            'settings' => $this->config->all(),
        ];
    }
}