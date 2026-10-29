<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Add portable cached vector magnitudes; values are backfilled by cron in bounded pages. */
final class Version118040Date20261029000007 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('eva_ai_chunks')) {
			$table = $schema->getTable('eva_ai_chunks');
			if (!$table->hasColumn('vector_norm')) $table->addColumn('vector_norm', Types::FLOAT, ['notnull' => false]);
			if (!$table->hasIndex('eva_chunk_norm_due')) $table->addIndex(['vector_norm', 'id'], 'eva_chunk_norm_due');
		}
		return $schema;
	}
}
