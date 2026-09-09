<?php

declare(strict_types=1);
namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AgentStore;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AgentStoreClaimTest extends TestCase {
    private string $path;
    protected function setUp(): void {
        if (!EVA_AI_OCP_AVAILABLE || !extension_loaded('pdo_sqlite')) self::markTestSkipped('OCP and SQLite required');
        $this->path = tempnam(sys_get_temp_dir(), 'eva-claims-');
        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->exec('CREATE TABLE eva_ai_agent_state (id INTEGER PRIMARY KEY, user_id TEXT, token TEXT, history TEXT, pending TEXT, updated_at INTEGER, UNIQUE(user_id, token))');
    }
    protected function tearDown(): void { if (isset($this->path)) unlink($this->path); }

    private function store(): AgentStore {
        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout=5000');
        $db = $this->createMock(IDBConnection::class);
        $db->method('prepare')->willReturnCallback(function ($sql) use ($pdo) {
            $native = $pdo->prepare(str_replace('*PREFIX*', '', $sql));
            $stmt = $this->createMock(IPreparedStatement::class);
            $stmt->method('execute')->willReturnCallback(function ($params) use ($native) {
                $native->execute($params);
                return $this->createMock(IResult::class);
            });
            $stmt->method('fetch')->willReturnCallback(static function () use ($native) {
                $row = $native->fetch(\PDO::FETCH_ASSOC);
                $native->closeCursor();
                return $row;
            });
            $stmt->method('rowCount')->willReturnCallback(static fn() => $native->rowCount());
            return $stmt;
        });
        return new AgentStore($db, new NullLogger());
    }

    public function testClaimSurvivesWorkerExitAndBlocksReplayAndNewProposal(): void {
        $pending = [['name' => 'create_file', 'args' => ['path' => 'synthetic.txt']]];
        $store = $this->store();
        $store->save('alice', 'token', [], $pending);
        $claim = $store->claim('alice', 'token', $pending);
        self::assertNotNull($claim);
        unset($store); // A new connection observes the persisted in-progress claim.
        $store = $this->store();
        self::assertNull($store->claim('alice', 'token', $pending));
        self::assertSame([], $store->load('alice', 'token')['pending']);
        self::assertSame('in_progress', $store->load('alice', 'token')['execution']['status']);
        try {
            $store->save('alice', 'token', [], $pending);
            self::fail('An unrecorded outcome must not be overwritten');
        } catch (\RuntimeException $e) { self::assertStringContainsString('unrecorded outcome', $e->getMessage()); }
        $store->complete('alice', 'token', $claim['claim'], [], [['ok' => false]], 'Permission denied');
        self::assertSame('Permission denied', $store->load('alice', 'token')['execution']['output']);
        $store->save('alice', 'token', [], [['name' => 'different']]);
        $this->expectException(\RuntimeException::class);
        $store->complete('alice', 'token', $claim['claim'], [], [], 'Late summary');
    }

    public function testTwoWorkersCannotBothClaimTheSameProposal(): void {
        if (!function_exists('pcntl_fork')) self::markTestSkipped('pcntl required');
        $pending = [['name' => 'count_test_action']];
        $this->store()->save('alice', 'token', [], $pending);
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                try { exit($this->store()->claim('alice', 'token', $pending) === null ? 11 : 10); }
                catch (\Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(12); }
            }
            $workers[] = $pid;
        }
        $outcomes = [];
        foreach ($workers as $pid) { pcntl_waitpid($pid, $status); $outcomes[] = pcntl_wexitstatus($status); }
        sort($outcomes);
        self::assertSame([10, 11], $outcomes);
    }

    public function testStorageFailurePropagatesBeforeClaimAndPreservesExistingReceipt(): void {
        $store = $this->store();
        $pending = [['name' => 'count_test_action']];
        $store->save('alice', 'token', [], $pending);
        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->exec("CREATE TRIGGER reject_updates BEFORE UPDATE ON eva_ai_agent_state BEGIN SELECT RAISE(FAIL, 'test storage unavailable'); END");
        try { $store->claim('alice', 'token', $pending); self::fail('Claim must fail'); }
        catch (\PDOException $e) { self::assertStringContainsString('storage unavailable', $e->getMessage()); }
        self::assertSame($pending, $store->load('alice', 'token')['pending']);
        $pdo->exec('DROP TRIGGER reject_updates');
        $claim = $store->claim('alice', 'token', $pending);
        $pdo->exec("CREATE TRIGGER reject_updates BEFORE UPDATE ON eva_ai_agent_state BEGIN SELECT RAISE(FAIL, 'test storage unavailable'); END");
        try { $store->complete('alice', 'token', $claim['claim'], [], [], 'Done'); self::fail('Receipt must fail'); }
        catch (\PDOException $e) { self::assertStringContainsString('storage unavailable', $e->getMessage()); }
        self::assertNull($store->claim('alice', 'token', $pending));
        self::assertSame('in_progress', $store->load('alice', 'token')['execution']['status']);
    }
}
