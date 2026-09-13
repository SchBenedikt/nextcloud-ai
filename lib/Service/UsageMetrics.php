<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Records model usage without storing prompts, answers or tool arguments.
 * Provider token counts are used when available; otherwise the UI labels the
 * conservative character based estimate clearly.
 */
class UsageMetrics {
	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	public static function estimateTokens(string $text): int {
		return $text === '' ? 0 : max(1, (int)ceil(mb_strlen($text) / 4));
	}

	/** @param array<int,array{role?:string,content?:string}> $messages */
	public function recordChat(
		?string $userId,
		string $provider,
		string $model,
		array $messages,
		string $answer,
		?int $inputTokens = null,
		?int $outputTokens = null,
		int $durationMs = 0,
	): void {
		if ($userId === null || $userId === '') {
			return;
		}
		$estimated = $inputTokens === null || $outputTokens === null;
		$inputTokens ??= self::estimateTokens(implode("\n", array_map(static fn(array $message): string => (string)($message['content'] ?? ''), $messages)));
		$outputTokens ??= self::estimateTokens($answer);
		$inputTokens = max(0, min($inputTokens, 2147483647));
		$outputTokens = max(0, min($outputTokens, 2147483647));
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('eva_ai_usage')
				->values([
					'user_id' => $qb->createNamedParameter($userId),
					'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
					'provider' => $qb->createNamedParameter(substr($provider, 0, 32)),
					'model' => $qb->createNamedParameter(substr($model, 0, 128)),
					'operation' => $qb->createNamedParameter('chat'),
					'input_tokens' => $qb->createNamedParameter($inputTokens, IQueryBuilder::PARAM_INT),
					'output_tokens' => $qb->createNamedParameter($outputTokens, IQueryBuilder::PARAM_INT),
					'total_tokens' => $qb->createNamedParameter($inputTokens + $outputTokens, IQueryBuilder::PARAM_INT),
					'estimated' => $qb->createNamedParameter($estimated ? 1 : 0, IQueryBuilder::PARAM_INT),
					'duration_ms' => $qb->createNamedParameter(max(0, $durationMs), IQueryBuilder::PARAM_INT),
				])
				->executeStatement();
		} catch (\Throwable $e) {
			// Metrics must never make a successful answer fail, including while an
			// older installation is still running its database migration.
			$this->logger->debug('eva_ai usage metric could not be stored', ['exception' => $e->getMessage()]);
		}
	}

	/** Record only slow tool calls; never persist arguments or returned data. */
	public function recordTool(?string $userId, string $tool, int $durationMs, bool $ok): void {
		if ($userId === null || $userId === '' || $durationMs < 100) return;
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('eva_ai_usage')->values([
				'user_id' => $qb->createNamedParameter($userId), 'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				'provider' => $qb->createNamedParameter('internal'), 'model' => $qb->createNamedParameter(substr($tool, 0, 128)),
				'operation' => $qb->createNamedParameter('tool'), 'input_tokens' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'output_tokens' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT), 'total_tokens' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'estimated' => $qb->createNamedParameter($ok ? 0 : 1, IQueryBuilder::PARAM_INT), 'duration_ms' => $qb->createNamedParameter(max(0, $durationMs), IQueryBuilder::PARAM_INT),
			])->executeStatement();
		} catch (\Throwable $e) {
			$this->logger->debug('eva_ai tool metric could not be stored', ['exception' => $e->getMessage()]);
		}
	}

	/** @return array{totals:array<string,int>,by_model:list<array<string,mixed>>,daily:list<array<string,mixed>>} */
	public function summaryForUser(string $userId, int $days = 30): array {
		$since = time() - max(1, min(365, $days)) * 86400;
		$totals = ['requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'estimated_requests' => 0];
		$byModel = [];
		$daily = [];
		$slowTools = [];
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('provider', 'model')
				->selectAlias($qb->createFunction('COUNT(*)'), 'requests')
				->selectAlias($qb->createFunction('SUM(input_tokens)'), 'input_tokens')
				->selectAlias($qb->createFunction('SUM(output_tokens)'), 'output_tokens')
				->selectAlias($qb->createFunction('SUM(total_tokens)'), 'total_tokens')
				->selectAlias($qb->createFunction('SUM(estimated)'), 'estimated_requests')
				->from('eva_ai_usage')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('operation', $qb->createNamedParameter('chat')))
				->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
				->groupBy('provider', 'model')
				->orderBy('total_tokens', 'DESC');
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$item = $this->numbers($row) + ['provider' => (string)$row['provider'], 'model' => (string)$row['model']];
				$byModel[] = $item;
				foreach (array_keys($totals) as $key) $totals[$key] += $item[$key];
			}
			$result->closeCursor();

			$qb = $this->db->getQueryBuilder();
			$qb->select('created_at', 'input_tokens', 'output_tokens', 'total_tokens')
				->from('eva_ai_usage')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('operation', $qb->createNamedParameter('chat')))
				->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
				->orderBy('created_at', 'ASC');
			$result = $qb->executeQuery();
			$dailyMap = [];
			while ($row = $result->fetch()) {
				$day = gmdate('Y-m-d', (int)$row['created_at']);
				$dailyMap[$day] ??= ['day' => $day, 'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'estimated_requests' => 0];
				$dailyMap[$day]['requests']++;
				$dailyMap[$day]['input_tokens'] += (int)($row['input_tokens'] ?? 0);
				$dailyMap[$day]['output_tokens'] += (int)($row['output_tokens'] ?? 0);
				$dailyMap[$day]['total_tokens'] += (int)($row['total_tokens'] ?? 0);
			}
			$result->closeCursor();
			$daily = array_values($dailyMap);

			$qb = $this->db->getQueryBuilder();
			$qb->select('model')
				->selectAlias($qb->createFunction('COUNT(*)'), 'calls')
				->selectAlias($qb->createFunction('AVG(duration_ms)'), 'avg_duration_ms')
				->selectAlias($qb->createFunction('MAX(duration_ms)'), 'max_duration_ms')
				->selectAlias($qb->createFunction('SUM(estimated)'), 'errors')
				->from('eva_ai_usage')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('operation', $qb->createNamedParameter('tool')))
				->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
				->groupBy('model')->orderBy('avg_duration_ms', 'DESC');
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$slowTools[] = ['tool' => (string)$row['model'], 'calls' => (int)$row['calls'], 'avg_duration_ms' => (int)round((float)$row['avg_duration_ms']), 'max_duration_ms' => (int)$row['max_duration_ms'], 'errors' => (int)$row['errors']];
			}
			$result->closeCursor();
		} catch (\Throwable $e) {
			$this->logger->debug('eva_ai usage metrics unavailable', ['exception' => $e->getMessage()]);
		}
		return ['days' => $days, 'totals' => $totals, 'by_model' => $byModel, 'daily' => $daily, 'slow_tools' => $slowTools];
	}

	/** @return array<string,int> */
	private function numbers(array $row): array {
		return [
			'requests' => (int)($row['requests'] ?? 0),
			'input_tokens' => (int)($row['input_tokens'] ?? 0),
			'output_tokens' => (int)($row['output_tokens'] ?? 0),
			'total_tokens' => (int)($row['total_tokens'] ?? 0),
			'estimated_requests' => (int)($row['estimated_requests'] ?? 0),
		];
	}
}
