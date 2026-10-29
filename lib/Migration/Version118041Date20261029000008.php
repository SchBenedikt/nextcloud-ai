<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Add a portable token postings table; background work fills existing rows. */
final class Version118041Date20261029000008 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('eva_ai_chunks')) {
			$chunks = $schema->getTable('eva_ai_chunks');
			if (!$chunks->hasColumn('lexical_indexed')) $chunks->addColumn('lexical_indexed', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			if (!$chunks->hasIndex('eva_chunk_lex_due')) $chunks->addIndex(['lexical_indexed', 'id'], 'eva_chunk_lex_due');
		}
		if (!$schema->hasTable('eva_ai_chunk_terms')) {
			$table = $schema->createTable('eva_ai_chunk_terms');
			$table->addColumn('chunk_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('document_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('term_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('frequency', Types::INTEGER, ['notnull' => true]);
			$table->setPrimaryKey(['chunk_id', 'term_hash']);
			$table->addIndex(['term_hash', 'chunk_id'], 'eva_ai_term_lookup');
			$table->addIndex(['document_id', 'chunk_id'], 'eva_ai_term_document');
		}
		return $schema;
	}
}
