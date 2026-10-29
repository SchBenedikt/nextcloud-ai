<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Store low-cardinality embedding latency and cache samples with usage retention. */
final class Version118078Date20261029000015 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('eva_ai_usage')) return $schema;

		$table = $schema->getTable('eva_ai_usage');
		foreach (['embedding_cache_hits', 'embedding_cache_misses'] as $column) {
			if (!$table->hasColumn($column)) {
				$table->addColumn($column, Types::INTEGER, ['notnull' => true, 'default' => 0]);
			}
		}
		if (!$table->hasIndex('eva_ai_usage_embed_sample')) {
			$table->addIndex(['operation', 'provider', 'created_at'], 'eva_ai_usage_embed_sample');
		}
		return $schema;
	}
}
