<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use Doctrine\DBAL\Types\Type;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Normalize the usage-estimate flag to the integer type used by aggregates. */
final class Version118044Date20261029000011 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('eva_ai_usage')) {
			$table = $schema->getTable('eva_ai_usage');
			if ($table->hasColumn('estimated')) {
				$table->changeColumn('estimated', [
					'type' => Type::getType(Types::INTEGER),
					'default' => 0,
					'notnull' => true,
				]);
			}
		}
		return $schema;
	}
}
