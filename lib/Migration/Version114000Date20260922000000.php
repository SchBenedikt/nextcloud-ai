<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Add the composite index used by document chunk pagination. */
final class Version114000Date20260922000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('eva_ai_chunks')) {
            return $schema;
        }
        $table = $schema->getTable('eva_ai_chunks');
        $name = 'eva_ai_chunk_doc_index';
        if (!$table->hasIndex($name)) {
            $table->addIndex(['document_id', 'chunk_index'], $name);
        }
        return $schema;
    }
}
