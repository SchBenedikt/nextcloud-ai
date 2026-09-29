<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use LogicException;

final class ShareToolExecutor implements DomainToolExecutor {
	private const TOOLS = ['list_shares', 'create_share', 'update_share', 'delete_share'];

	public function __construct(private SharesService $shares) {
	}

	public function tools(): array {
		return self::TOOLS;
	}

	public function execute(string $tool, string $userId, array $args): array {
		return match ($tool) {
			'list_shares' => $this->shares->list($userId, $args),
			'create_share' => $this->shares->create($userId, $args),
			'update_share' => $this->shares->update($userId, $args),
			'delete_share' => $this->shares->delete($userId, $args),
			default => throw new LogicException('Unsupported share tool: ' . $tool),
		};
	}
}
