<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Minimal persistent ownership fence, without enumerating or contacting users. */
final class Version118002Date20261028000002 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('eva_ai_vec_owners')) {
            $table = $schema->createTable('eva_ai_vec_owners');
            $table->addColumn('owner_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('state', Types::STRING, ['length' => 16, 'notnull' => true]);
            $table->addColumn('revision', Types::BIGINT, ['default' => 0, 'notnull' => true]);
            $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['owner_hash']);
        }
        return $schema;
    }
}
