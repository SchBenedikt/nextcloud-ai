<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Allow the explicit Qdrant retrieval mode in the durable owner state. */
final class Version118025Date20261029000002 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('eva_ai_vec_state')) {
			$table = $schema->getTable('eva_ai_vec_state');
			if ($table->hasColumn('retrieval_backend')) {
				$table->changeColumn('retrieval_backend', ['length' => 32]);
			}
		}
		return $schema;
	}
}
