<?php

declare(strict_types=1);

namespace OCA\RagChat\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version100000Date20260801000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('ragchat_documents')) {
			$table = $schema->createTable('ragchat_documents');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('file_id', 'bigint', ['notnull' => true]);
			$table->addColumn('path', 'text', ['notnull' => true]);
			$table->addColumn('name', 'string', ['notnull' => true, 'length' => 255]);
			$table->addColumn('mime', 'string', ['notnull' => false, 'length' => 128]);
			$table->addColumn('size', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->addColumn('content_hash', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('chunk_count', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('indexed_at', 'bigint', ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id', 'file_id'], 'ragchat_doc_user_file');
			$table->addIndex(['user_id'], 'ragchat_doc_user');
		}

		if (!$schema->hasTable('ragchat_chunks')) {
			$table = $schema->createTable('ragchat_chunks');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('document_id', 'bigint', ['notnull' => true]);
			$table->addColumn('chunk_index', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('content', 'text', ['notnull' => true]);
			$table->addColumn('embedding', 'text', ['notnull' => true]);
			$table->addColumn('token_count', 'integer', ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['document_id'], 'ragchat_chunk_doc');
		}

		return $schema;
	}
}