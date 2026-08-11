<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Repariert Index-Namen der ersten Migration (Version100000...).
 *
 * Die erste Migration legte die Indizes mit Bindestrich an
 * ("eva_ai_doc_user_file", "eva_ai_doc_user", "eva_ai_chunk_doc").
 * Bindestriche sind in MySQL/PostgreSQL-DDL unzulässig, deshalb
 * schlug das Erstellen dieser Indizes auf manchen Instanzen fehl
 * (der Rest der Tabelle wurde aber angelegt). Das kann im
 * TaskProcessing/Assistant-Kontext zu kaputten Schema-Abfragen
 * führen.
 *
 * Diese Migration korrigiert Bestands-Instanzen:
 *  - existierende "eva_ai_*"-Indizes werden zu "eva_ai_*" umbenannt
 *  - fehlende Indizes (weil damals fehlgeschlagen) werden neu angelegt
 */
class Version104000Date20260812000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('eva_ai_documents')) {
			$table = $schema->getTable('eva_ai_documents');
			// Alte (fehlerhafte) Namen -> umbenennen.
			if ($table->hasIndex('eva_ai_doc_user_file')) {
				$table->renameIndex('eva_ai_doc_user_file', 'eva_ai_doc_user_file');
				$output->info('Renamed index eva_ai_doc_user_file -> eva_ai_doc_user_file');
			}
			if ($table->hasIndex('eva_ai_doc_user')) {
				$table->renameIndex('eva_ai_doc_user', 'eva_ai_doc_user');
				$output->info('Renamed index eva_ai_doc_user -> eva_ai_doc_user');
			}
			// Falls die Indizes damals gar nicht erzeugt wurden -> anlegen.
			if (!$table->hasIndex('eva_ai_doc_user_file')) {
				$table->addIndex(['user_id', 'file_id'], 'eva_ai_doc_user_file');
				$output->info('Created missing index eva_ai_doc_user_file');
			}
			if (!$table->hasIndex('eva_ai_doc_user')) {
				$table->addIndex(['user_id'], 'eva_ai_doc_user');
				$output->info('Created missing index eva_ai_doc_user');
			}
			// Ueberbleibsel aus der ragchat-Aera entfernen (Rename
			// ragchat -> eva_ai liess die Indizes stehen).
			if ($table->hasIndex('ragchat_doc_user_file')) {
				$table->dropIndex('ragchat_doc_user_file');
				$output->info('Dropped obsolete index ragchat_doc_user_file');
			}
			if ($table->hasIndex('ragchat_doc_user')) {
				$table->dropIndex('ragchat_doc_user');
				$output->info('Dropped obsolete index ragchat_doc_user');
			}
		}

		if ($schema->hasTable('eva_ai_chunks')) {
			$table = $schema->getTable('eva_ai_chunks');
			if ($table->hasIndex('eva_ai_chunk_doc')) {
				$table->renameIndex('eva_ai_chunk_doc', 'eva_ai_chunk_doc');
				$output->info('Renamed index eva_ai_chunk_doc -> eva_ai_chunk_doc');
			}
			if (!$table->hasIndex('eva_ai_chunk_doc')) {
				$table->addIndex(['document_id'], 'eva_ai_chunk_doc');
				$output->info('Created missing index eva_ai_chunk_doc');
			}
			if ($table->hasIndex('ragchat_chunk_doc')) {
				$table->dropIndex('ragchat_chunk_doc');
				$output->info('Dropped obsolete index ragchat_chunk_doc');
			}
		}

		return $schema;
	}
}
