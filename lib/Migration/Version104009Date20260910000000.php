<?php

declare(strict_types=1);
namespace OCA\EvaAi\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the indexed file's modification time to each document row. The indexer
 * uses it (together with the stored size) as a cheap fingerprint on full
 * passes: a file whose mtime and size are unchanged is skipped without
 * re-reading and re-parsing its content, so incremental runs stay fast even
 * on large libraries.
 */
final class Version104009Date20260910000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('eva_ai_documents')) {
            $table = $schema->getTable('eva_ai_documents');
            if (!$table->hasColumn('file_mtime')) {
                $table->addColumn('file_mtime', Types::BIGINT, [
                    'notnull' => false,
                    'default' => 0,
                ]);
            }
        }
        return $schema;
    }
}