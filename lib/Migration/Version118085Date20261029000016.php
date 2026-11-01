<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Index bounded per-outcome whole-request latency reads. */
final class Version118085Date20261029000016 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('eva_ai_usage')) {
			$table = $schema->getTable('eva_ai_usage');
			if (!$table->hasIndex('eva_ai_usage_rag_sample')) {
				$table->addIndex(['operation', 'model', 'created_at'], 'eva_ai_usage_rag_sample');
			}
		}
		return $schema;
	}
}
