<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Add the composite index used by user-scoped document status queries. */
final class Version115000Date20260922000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('eva_ai_documents')) {
            return $schema;
        }
        $table = $schema->getTable('eva_ai_documents');
        $name = 'eva_ai_doc_user_status';
        if (!$table->hasIndex($name)) {
            $table->addIndex(['user_id', 'status'], $name);
        }
        return $schema;
    }
}
