<?php
declare(strict_types=1);

namespace OCA\EvaAi\Dto;

/** One completed turn, validated before either message is written. */
final class ChatTurnRequest {
    private function __construct(
        public readonly string $requestId,
        public readonly int $expectedRev,
        public readonly ChatAppendRequest $question,
        public readonly ChatAppendRequest $answer,
    ) {}

    public static function fromArray(array $input): self {
        $id = $input['requestId'] ?? null;
        $rev = $input['expectedRev'] ?? null;
        if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $id) !== 1) throw new \InvalidArgumentException('requestId must contain 16 to 128 safe characters');
        if (!is_int($rev) || $rev < 0) throw new \InvalidArgumentException('expectedRev must be a non-negative integer');
        if (!is_array($input['answer'] ?? null)) throw new \InvalidArgumentException('answer must be an object');
        $question = ChatAppendRequest::fromArray(['role' => 'user', 'text' => $input['question'] ?? null]);
        $answer = ChatAppendRequest::fromArray(array_replace($input['answer'], ['role' => 'assistant']));
        if ($answer->regenerateRev !== null) throw new \InvalidArgumentException('A new turn cannot carry a regeneration revision');
        return new self($id, $rev, $question, $answer);
    }

    public function fingerprint(): string {
        return hash('sha256', json_encode([$this->expectedRev, $this->question, $this->answer], JSON_THROW_ON_ERROR));
    }
}
