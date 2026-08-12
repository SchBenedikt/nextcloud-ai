<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Repairs index names left by the early eva-ai/ragchat schema migrations.
 *
 * The legacy source names really contained a hyphen (for example
 * `eva-ai_doc_user_file`). The current app-id uses underscores, so the
 * migration must refer to those old names literally and must never attempt a
 * self-rename. It is safe for clean, partially migrated, and already repaired
 * installations.
 */
class Version104000Date20260812000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if ($schema->hasTable('eva_ai_documents')) {
            $table = $schema->getTable('eva_ai_documents');
            $this->repairIndex($table, $output, ['user_id', 'file_id'], 'eva-ai_doc_user_file', 'eva_ai_doc_user_file');
            $this->repairIndex($table, $output, ['user_id'], 'eva-ai_doc_user', 'eva_ai_doc_user');
            $this->dropIndexIfPresent($table, $output, 'ragchat_doc_user_file');
            $this->dropIndexIfPresent($table, $output, 'ragchat_doc_user');
        }

        if ($schema->hasTable('eva_ai_chunks')) {
            $table = $schema->getTable('eva_ai_chunks');
            $this->repairIndex($table, $output, ['document_id'], 'eva-ai_chunk_doc', 'eva_ai_chunk_doc');
            $this->dropIndexIfPresent($table, $output, 'ragchat_chunk_doc');
        }

        return $schema;
    }

    private function repairIndex($table, IOutput $output, array $columns, string $legacy, string $current): void {
        if ($table->hasIndex($legacy)) {
            if ($table->hasIndex($current)) {
                // A previous partial run may have created the target already.
                $table->dropIndex($legacy);
                $output->info('Dropped duplicate legacy index ' . $legacy);
            } else {
                $table->renameIndex($legacy, $current);
                $output->info('Renamed index ' . $legacy . ' -> ' . $current);
            }
        }
        if (!$table->hasIndex($current)) {
            $table->addIndex($columns, $current);
            $output->info('Created missing index ' . $current);
        }
    }

    private function dropIndexIfPresent($table, IOutput $output, string $name): void {
        if ($table->hasIndex($name)) {
            $table->dropIndex($name);
            $output->info('Dropped obsolete index ' . $name);
        }
    }
}
