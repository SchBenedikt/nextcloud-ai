<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use LogicException;

final class EmailToolExecutor implements DomainToolExecutor {
	private const TOOLS = ['search_mails', 'list_mails', 'read_mail', 'unread_mail_count', 'summarize_emails'];

	public function __construct(
		private EmailService $email,
		private ?Ollama $ollama = null,
	) {
	}

	public function tools(): array {
		return self::TOOLS;
	}

	public function execute(string $tool, string $userId, array $args): array {
		return match ($tool) {
			'search_mails' => $this->search($userId, $args),
			'list_mails' => $this->list($userId, $args),
			'read_mail' => $this->read($userId, $args),
			'unread_mail_count' => $this->unreadCount($userId),
			'summarize_emails' => $this->summarize($userId, $args),
			default => throw new LogicException('Unsupported email tool: ' . $tool),
		};
	}

	private function search(string $userId, array $args): array {
		try {
			$mails = $this->email->search($userId, (string)($args['query'] ?? ''), max(1, (int)($args['limit'] ?? 10)));
			return ['ok' => true, 'result' => ['mails' => $mails]];
		} catch (\Throwable $e) {
			return ['ok' => false, 'error' => 'Mail access failed: ' . $e->getMessage()];
		}
	}

	private function list(string $userId, array $args): array {
		try {
			$mails = $this->email->listMessages($userId, max(1, (int)($args['limit'] ?? 15)), !empty($args['unread_only']));
			return ['ok' => true, 'result' => ['mails' => $mails]];
		} catch (\Throwable $e) {
			return ['ok' => false, 'error' => 'Mail access failed: ' . $e->getMessage()];
		}
	}

	private function read(string $userId, array $args): array {
		try {
			return $this->email->readMessage($userId, (int)($args['message_id'] ?? 0));
		} catch (\Throwable $e) {
			return ['ok' => false, 'error' => 'Mail access failed: ' . $e->getMessage()];
		}
	}

	private function unreadCount(string $userId): array {
		try {
			return ['ok' => true, 'result' => ['unread' => $this->email->unreadCount($userId)]];
		} catch (\Throwable $e) {
			return ['ok' => false, 'error' => 'Mail access failed: ' . $e->getMessage()];
		}
	}

	private function summarize(string $userId, array $args): array {
		if ($this->ollama === null) {
			return ['ok' => false, 'error' => 'Email summarization requires a configured chat provider.'];
		}
		$limit = max(1, min(20, (int)($args['limit'] ?? 8)));
		$query = trim((string)($args['query'] ?? ''));
		try {
			$rows = $query !== ''
				? $this->email->search($userId, $query, $limit)
				: $this->email->listMessages($userId, $limit, !empty($args['unread_only']));
			if ($rows === []) {
				return ['ok' => true, 'result' => ['count' => 0, 'summary' => 'No matching emails found.', 'messages' => []]];
			}
			$documents = [];
			$metadata = [];
			foreach (array_slice($rows, 0, $limit) as $row) {
				$id = (int)($row['id'] ?? 0);
				$full = $id > 0 ? $this->email->readMessage($userId, $id) : ['ok' => false];
				$mail = is_array($full['result'] ?? null) ? $full['result'] : $row;
				$body = trim((string)($mail['body'] ?? $mail['preview'] ?? ''));
				$documents[] = sprintf(
					"[%s] From: %s\nSubject: %s\nDate: %s\n%s",
					$id,
					(string)($mail['from'] ?? $row['from'] ?? ''),
					(string)($mail['subject'] ?? $row['subject'] ?? ''),
					(string)($mail['date'] ?? $row['sent'] ?? ''),
					mb_substr($body, 0, 5000),
				);
				$metadata[] = [
					'id' => $id,
					'subject' => (string)($mail['subject'] ?? $row['subject'] ?? ''),
					'from' => (string)($mail['from'] ?? $row['from'] ?? ''),
					'date' => (string)($mail['date'] ?? $row['sent'] ?? ''),
					'unread' => (bool)($row['unread'] ?? false),
				];
			}
			$focus = trim((string)($args['focus'] ?? ''));
			$prompt = 'Summarize these emails in the language used by most messages. Give a concise overview, then bullet key points, explicit action items and dates/deadlines. Do not invent facts; say when a detail is unclear.';
			if ($focus !== '') {
				$prompt .= ' Pay special attention to: ' . mb_substr($focus, 0, 300) . '.';
			}
			$response = $this->ollama->chat([
				['role' => 'system', 'content' => 'You are EVA, a careful email assistant. Never expose secrets or claim an action was taken.'],
				['role' => 'user', 'content' => $prompt . "\n\n" . implode("\n\n", $documents)],
			], [], 90);
			$summary = trim((string)($response['answer'] ?? ''));
			if ($summary === '') {
				return ['ok' => false, 'error' => (string)($response['error'] ?? 'The chat provider returned no summary.')];
			}
			return ['ok' => true, 'result' => ['count' => count($metadata), 'summary' => $summary, 'messages' => $metadata]];
		} catch (\Throwable $e) {
			return ['ok' => false, 'error' => 'Mail summarization failed: ' . $e->getMessage()];
		}
	}
}
