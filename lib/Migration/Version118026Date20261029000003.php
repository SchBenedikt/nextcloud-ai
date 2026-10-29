<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Records the explicit per-generation consent to copy chunk text to Qdrant. */
final class Version118026Date20261029000003 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('eva_ai_vec_generations')) {
			$table = $schema->getTable('eva_ai_vec_generations');
			if (!$table->hasColumn('include_text_payload')) {
				$table->addColumn('include_text_payload', Types::SMALLINT, ['default' => 0, 'notnull' => true]);
			}
		}
		return $schema;
	}
}
