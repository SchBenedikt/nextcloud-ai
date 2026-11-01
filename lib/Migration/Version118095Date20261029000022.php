<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Store anonymous rate counters only when Nextcloud's locking cache is unavailable. */
final class Version118095Date20261029000022 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('eva_ai_rate_limits')) {
			$table = $schema->createTable('eva_ai_rate_limits');
			$table->addColumn('rate_key', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('window_start', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('request_count', Types::INTEGER, ['default' => 0, 'notnull' => true]);
			$table->addColumn('expires_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['rate_key', 'window_start']);
			$table->addIndex(['expires_at'], 'eva_ai_rate_exp');
		}
		return $schema;
	}
}
