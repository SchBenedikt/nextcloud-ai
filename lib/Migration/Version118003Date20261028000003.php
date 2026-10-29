<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Fast generation/document/slot lookup; NULL legacy generations stay independent. */
final class Version118003Date20261028000003 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('eva_ai_chunks')) {
            $table = $schema->getTable('eva_ai_chunks');
            if (!$table->hasIndex('eva_vec_chunk_slot')) $table->addUniqueIndex(
                ['vector_generation_id', 'document_id', 'chunk_index'], 'eva_vec_chunk_slot');
        }
        return $schema;
    }
}
