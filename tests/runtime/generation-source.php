<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('EVA_TEST_CREATE_DELETE_ACCOUNT') !== '1') {
    throw new RuntimeException('CLI only; explicitly set EVA_TEST_CREATE_DELETE_ACCOUNT=1.');
}
$root = getenv('NEXTCLOUD_ROOT');
$uid = getenv('EVA_TEST_USER');
if (!$root || !is_file($root . '/occ') || !$uid || !str_starts_with($uid, 'eva-runtime-audit-')) {
    throw new RuntimeException('Set NEXTCLOUD_ROOT and a fresh disposable EVA_TEST_USER (eva-runtime-audit-*).');
}
define('OC_CONSOLE', 1);
$_SERVER['SCRIPT_FILENAME'] = $root . '/occ';
$_SERVER['SCRIPT_NAME'] = '/occ';
chdir($root);
require $root . '/lib/base.php';

use OCA\EvaAi\Db\{Document, DocumentMapper, GenerationChunkRepository};
use OCA\EvaAi\Service\{AppConfig, IndexDocumentText, Indexer};
use OCA\EvaAi\Vector\{GenerationDocumentPreparer, GenerationSourceReader, VectorGeneration, VectorStoreException};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$users = OC::$server->get(OCP\IUserManager::class);
$assert(!$users->userExists($uid), 'Use a fresh disposable account name; existing accounts are never touched.');
$user = $users->createUser($uid, bin2hex(random_bytes(24)));
$assert($user !== false && $user !== null, 'Disposable account creation failed.');
$config = OC::$server->get(AppConfig::class);
$previous = $config->userId();
try {
    $config->setUserId($uid);
    $home = OC::$server->get(OCP\Files\IRootFolder::class)->getUserFolder($uid);
    $file = $home->newFile('Public source fixture.txt', str_repeat('Public source line. ', 8000) . "\nFinal source marker.");
    $canonical = OC::$server->get(IndexDocumentText::class)->file($file);
    $agent = OC::$server->get(Indexer::class)->extractTextForAgent($file, 100000);
    $assert(mb_strlen($agent) === 100000 && !str_contains($agent, 'Final source marker.'), 'Agent excerpt no longer has its independent bound.');
    $document = new Document();
    $document->setUserId($uid); $document->setFileId($file->getId());
    $document->setPath($file->getName()); $document->setName($file->getName());
    $document->setSource('files'); $document->setMime('text/plain');
    $document->setSize($file->getSize()); $document->setFileMtime($file->getMTime());
    $document->setContentHash(md5($canonical)); $document->setChunkCount(0);
    $documents = OC::$server->get(DocumentMapper::class);
    $document = $documents->insert($document);
    $snapshot = OC::$server->get(GenerationChunkRepository::class)->snapshot($uid, (int)$document->getId());
    $assert($snapshot !== null, 'Canonical SQL snapshot is missing.');
    $generation = new VectorGeneration(bin2hex(random_bytes(16)), $uid, 'qdrant', 'ollama', null,
        'public-source-fixture', hash('sha256', 'fixture-only'), 3, 512, 50);
    $preparer = OC::$server->get(GenerationDocumentPreparer::class);
    $plan = $preparer->prepare($generation, $snapshot);
    $assert(str_contains(implode(' ', array_column($plan->chunks, 'content')), 'Final source marker.'), 'Generation lost the source tail.');
    foreach ($plan->chunks as $chunk) $assert(mb_strlen($chunk['content']) <= 512, 'Generation used mutable user chunk settings.');
    $assert($plan->planHash === $preparer->prepare($generation, $snapshot)->planHash, 'Same canonical source/settings produced a different plan.');
    $file->putContent(str_replace('Final source marker.', 'Other source marker.', $canonical));
    try {
        OC::$server->get(GenerationSourceReader::class)->read($snapshot);
        throw new RuntimeException('Changed native file was accepted.');
    } catch (VectorStoreException $error) {
        $assert(in_array($error->reason, ['source_changed', 'source_unavailable'], true), 'Unexpected source-change result.');
    }
    echo "PASS: installed native DI/VFS/SQL source preparation, full text beyond agent limit, pinned bounded chunks, stable plan identity and changed-source rejection.\n";
    echo "No embedding or vector provider was contacted and no generation was activated.\n";
} finally {
    $config->setUserId($previous);
    $assert($user->delete(), 'Disposable account deletion failed.');
}
$assert(!$users->userExists($uid), 'Disposable account still exists.');
$assert(OC::$server->get(DocumentMapper::class)->countForUser($uid) === 0, 'Source fixture ownership was not erased.');
echo "Disposable account and source rows removed through native account deletion.\n";
