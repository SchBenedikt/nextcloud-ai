<?php

declare(strict_types=1);
namespace OCA\EvaAi\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version104008Date20260909000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('eva_ai_chunks')) {
            $table = $schema->getTable('eva_ai_chunks');
            if (!$table->hasColumn('provenance')) {
                $table->addColumn('provenance', Types::TEXT, ['notnull' => false]);
            }
        }
        return $schema;
    }
}
