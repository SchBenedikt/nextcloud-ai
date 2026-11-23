<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Index terminal outbox timestamps for bounded retention scans. */
final class Version120051Date20261123000001 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('eva_ai_vec_outbox')) {
			$table = $schema->getTable('eva_ai_vec_outbox');
			if (!$table->hasIndex('eva_vec_outbox_term_del')) $table->addIndex(['state', 'delivered_at', 'id'], 'eva_vec_outbox_term_del');
			if (!$table->hasIndex('eva_vec_outbox_term_sup')) $table->addIndex(['state', 'created_at', 'id'], 'eva_vec_outbox_term_sup');
		}
		return $schema;
	}
}
