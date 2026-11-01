<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Persist erasure grace independently of retry/claim counts. */
final class Version118004Date20261028000004 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('eva_ai_vec_outbox')) {
            $table = $schema->getTable('eva_ai_vec_outbox');
            if (!$table->hasColumn('erasure_ready_at')) $table->addColumn('erasure_ready_at', Types::BIGINT, ['notnull' => false]);
        }
        return $schema;
    }
}
