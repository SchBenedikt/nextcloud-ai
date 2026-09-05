<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * App-Id-Umbenennung: eva_ai (vorher ragchat).
 * Benennt die Tabellen um, falls sie unter dem alten Namen existieren.
 */
class Version101000Date20260810000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$renames = [
			'ragchat_documents' => 'eva_ai_documents',
			'ragchat_chunks'    => 'eva_ai_chunks',
		];
		foreach ($renames as $old => $new) {
			if (!$schema->hasTable($old)) {
				continue;
			}
			if (!$schema->hasTable($new)) {
				$schema->renameTable($old, $new);
				continue;
			}

			// A partial upgrade can leave both names behind. The current table
			// is authoritative; drop the obsolete duplicate instead of silently
				// preserving stale indexed data forever.
			$schema->dropTable($old);
			$output->warning('Dropped obsolete legacy table ' . $old . ' because ' . $new . ' already exists.');
		}
		return $schema;
	}
}
