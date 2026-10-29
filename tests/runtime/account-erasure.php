<?php

declare(strict_types=1);

// Opt-in destructive lifecycle check: only a dedicated disposable audit account.
if (PHP_SAPI !== 'cli' || getenv('EVA_TEST_DELETE_ACCOUNT') !== '1') {
    throw new RuntimeException('CLI only; explicitly set EVA_TEST_DELETE_ACCOUNT=1.');
}
$root = getenv('NEXTCLOUD_ROOT');
$uid = getenv('EVA_TEST_USER');
if (!$root || !is_file($root . '/occ') || !$uid || !str_starts_with($uid, 'eva-runtime-audit-')) {
    throw new RuntimeException('Set NEXTCLOUD_ROOT and a disposable EVA_TEST_USER (eva-runtime-audit-*).');
}
define('OC_CONSOLE', 1);
$_SERVER['SCRIPT_FILENAME'] = $root . '/occ';
$_SERVER['SCRIPT_NAME'] = '/occ';
chdir($root);
require $root . '/lib/base.php';

use OCA\EvaAi\Db\{Chunk, ChunkMapper, Document, DocumentMapper, VectorGenerationRepository};
use OCA\EvaAi\Service\{AgentStore, BackgroundChatQueue, ChatStore, DirtyIndexStore, UserDataService};
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    // No frame arguments: those may contain credentials or message contents.
    foreach ($error->getTrace() as $frame) {
        fwrite(STDERR, ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '')
            . ' at ' . ($frame['file'] ?? '[internal]') . ':' . ($frame['line'] ?? 0) . PHP_EOL);
    }
    exit(1);
});
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$user = OC::$server->get(OCP\IUserManager::class)->get($uid);
$assert($user !== null, 'Create the dedicated disposable audit account first.');
$db = OC::$server->get(OCP\IDBConnection::class);
$config = OC::$server->get(OCP\IConfig::class);
$documents = OC::$server->get(DocumentMapper::class);
$chunks = OC::$server->get(ChunkMapper::class);
$generations = OC::$server->get(VectorGenerationRepository::class);
$home = OC::$server->get(OCP\Files\IRootFolder::class)->getUserFolder($uid);
$file = $home->newFile('EVA account erasure ' . bin2hex(random_bytes(4)) . '.txt', 'Public erasure fixture.');
$document = new Document();
$document->setUserId($uid);
$document->setFileId($file->getId());
$document->setPath($file->getName());
$document->setName($file->getName());
$document->setSource('files');
$document->setMime('text/plain');
$document->setSize($file->getSize());
$document->setFileMtime($file->getMTime());
$document->setContentHash(md5('Public erasure fixture.'));
$document->setChunkCount(2);
$document = $documents->insert($document);
$buildId = bin2hex(random_bytes(16));
$db->insertIgnoreConflict('eva_ai_vec_builds', ['generation_id'=>$buildId, 'user_id'=>$uid,
    'owner_hash'=>\OCA\EvaAi\Db\VectorOwnerFenceRepository::ownerHash($uid), 'target_hash'=>hash('sha256','public-erasure-fixture'), 'status'=>'paused']);
$db->insertIgnoreConflict('eva_ai_vec_build_docs', ['generation_id'=>$buildId, 'user_id'=>$uid, 'document_id'=>(int)$document->getId(),
    'source_stamp'=>hash('sha256','public-source'), 'plan_hash'=>hash('sha256','public-plan'), 'chunk_count'=>2]);
foreach ([0, 1] as $index) {
    $chunk = new Chunk();
    $chunk->setDocumentId($document->getId());
    $chunk->setChunkIndex($index);
    $chunk->setContent('Public erasure fixture.');
    $chunk->setEmbeddingArray([1, 0, 0]);
    $chunk->setTokenCount(5);
    $chunk->setProvenanceArray([]);
    $chunks->insert($chunk);
}
OC::$server->get(ChatStore::class)->create($uid, 'Public erasure fixture');
OC::$server->get(DirtyIndexStore::class)->markFile($uid, $file->getId());
OC::$server->get(AgentStore::class)->save($uid, 'erasure-fixture', [], []);
$config->setUserValue($uid, 'eva_ai', BackgroundChatQueue::HISTORY_KEY, '[]');
$appData = OC::$server->get(IAppDataFactory::class)->get('eva_ai');
try { $marks = $appData->getFolder('ai-marks'); }
catch (NotFoundException) { $marks = $appData->newFolder('ai-marks'); }
$namespace = substr(hash('sha256', $uid), 0, 40);
try { $marks->getFolder($namespace); }
catch (NotFoundException) { $marks->newFolder($namespace); }
$assert($chunks->countForUser($uid) >= 2, 'Chunk fixture was not persisted.');
$assert($user->delete(), 'Native account deletion failed.');

$count = static function (string $table, string $column) use ($db, $uid): int {
    $query = $db->getQueryBuilder();
    $query->selectAlias($query->createFunction('COUNT(*)'), 'n')->from($table)
        ->where($query->expr()->eq($column, $query->createNamedParameter($uid)));
    if ($table === 'preferences') $query->andWhere($query->expr()->eq('appid', $query->createNamedParameter('eva_ai')));
    $result = $query->executeQuery();
    try { return (int)$result->fetchOne(); }
    finally { $result->closeCursor(); }
};
$check = static function () use ($assert, $count, $uid, $db, $appData, $namespace, $generations, $config): void {
    $assert(!OC::$server->get(OCP\IUserManager::class)->userExists($uid), 'Audit account still exists.');
    foreach (['eva_ai_documents', 'eva_ai_agent_state', 'eva_ai_vec_build_docs', 'eva_ai_vec_builds', 'eva_ai_vec_generations', 'eva_ai_vec_state'] as $table) {
        $assert($count($table, 'user_id') === 0, 'Account erasure retained rows in ' . $table);
    }
    $assert($count('preferences', 'userid') === 0, 'Account erasure retained EVA preferences.');
    $assert($generations->ownerStatus($uid) === 'blocked', 'Durable erasure fence is missing.');
    // Reading stores through their normal API can recreate files; inspect only.
    foreach (['chats', 'ai-marks'] as $container) {
        try { $folder = $appData->getFolder($container); }
        catch (NotFoundException) { continue; }
        foreach ([$namespace, preg_replace('/[^a-zA-Z0-9_-]/', '_', $uid)] as $candidate) {
            try { $folder->getFolder($candidate); }
            catch (NotFoundException) { continue; }
            throw new RuntimeException('Account erasure retained an AppData namespace in ' . $container);
        }
    }
    try {
        $dirty = $appData->getFolder('dirty');
        $assert(!$dirty->fileExists($namespace . '.json'), 'Account erasure retained the dirty queue.');
    } catch (NotFoundException) {}
    $queueUsers = json_decode($config->getAppValue('eva_ai', 'background_chat_users', '[]'), true);
    $assert(is_array($queueUsers) && !in_array($uid, $queueUsers, true), 'Queue user registry retained the erased account.');
};
// Retain exact fixture document ID to detect orphaned chunks after parent removal.
$query = $db->getQueryBuilder();
$query->selectAlias($query->createFunction('COUNT(*)'), 'n')->from('eva_ai_chunks')
    ->where($query->expr()->eq('document_id', $query->createNamedParameter((int)$document->getId(), IQueryBuilder::PARAM_INT)));
$result = $query->executeQuery();
try { $assert((int)$result->fetchOne() === 0, 'Account erasure orphaned fixture chunks.'); }
finally { $result->closeCursor(); }
$check();
// Retry the same listener service after core has forgotten the account.
OC::$server->get(UserDataService::class)->cleanupDeletedAccount($uid);
$check();
echo "PASS: native account deletion and idempotent retry erase SQL rows, fixture chunks, EVA preferences and AppData, while retaining the blocked owner fence.\n";
echo "This is a local sequential lifecycle drill, not a concurrent-worker or remote-vector erasure proof.\n";
