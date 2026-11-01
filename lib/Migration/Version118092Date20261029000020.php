<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Attribute query-embedding stage durations to a fixed provider type. */
final class Version118092Date20261029000020 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('eva_ai_usage')) return $schema;

		$table = $schema->getTable('eva_ai_usage');
		if (!$table->hasColumn('retrieval_embedding_provider')) {
			$table->addColumn('retrieval_embedding_provider', Types::STRING, [
				'notnull' => true,
				'length' => 24,
				'default' => 'none',
			]);
		}
		return $schema;
	}
}
