<?php

declare(strict_types=1);
namespace OCA\EvaAi\Tests\Support;

/** Executes the real Nextcloud query builder against an in-memory SQLite DB. No server/bootstrap/install. */
trait SqliteDatabase {
    private function sqliteDatabase(): \OCP\IDBConnection {
        if (!class_exists(\OC\DB\ConnectionAdapter::class)) { $this->markTestSkipped('Nextcloud query builder sources required'); }
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE oc_eva_ai_chat_items (user_id TEXT, kind TEXT, item_id TEXT, title TEXT, data TEXT, updated_at INTEGER, project_id TEXT, archived INTEGER, pinned INTEGER, PRIMARY KEY(user_id,kind,item_id))');
        $pdo->exec('CREATE TABLE oc_eva_ai_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id TEXT, chat_id TEXT, role TEXT, content TEXT)');
        $inner = $this->createMock(\OC\DB\Connection::class);
        $inner->method('getDatabasePlatform')->willReturn(new \Doctrine\DBAL\Platforms\SqlitePlatform());
        $db = $this->createMock(\OC\DB\ConnectionAdapter::class);
        $db->method('getInner')->willReturn($inner);
        $db->method('getDatabaseProvider')->willReturn('sqlite');
        $db->method('escapeLikeParameter')->willReturnCallback(static fn($s) => addcslashes($s, '\\%_'));
        $db->method('getQueryBuilder')->willReturnCallback(function () use ($db) {
            return new \OC\DB\QueryBuilder\QueryBuilder($db, $this->createMock(\OC\SystemConfig::class), $this->createMock(\Psr\Log\LoggerInterface::class));
        });
        $execute = static function ($sql, $params) use ($pdo) {
            $stmt = $pdo->prepare(str_replace('*PREFIX*', 'oc_', $sql));
            foreach ($params as $k => $v) { $stmt->bindValue(is_string($k) ? ':' . ltrim($k, ':') : $k + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR); }
            $stmt->execute();
            return $stmt;
        };
        $db->method('executeStatement')->willReturnCallback(static fn($sql, $params = []) => $execute($sql, $params)->rowCount());
        $db->method('executeQuery')->willReturnCallback(function ($sql, $params = []) use ($execute) {
            $stmt = $execute($sql, $params);
            $result = $this->createMock(\OCP\DB\IResult::class);
            $result->method('fetch')->willReturnCallback(static fn() => $stmt->fetch(\PDO::FETCH_ASSOC));
            $result->method('fetchAll')->willReturnCallback(static fn() => $stmt->fetchAll(\PDO::FETCH_ASSOC));
            $result->method('closeCursor')->willReturnCallback(static fn() => $stmt->closeCursor());
            return $result;
        });
        $db->method('beginTransaction')->willReturnCallback(static function () use ($pdo) { $pdo->beginTransaction(); });
        $db->method('commit')->willReturnCallback(static function () use ($pdo) { $pdo->commit(); });
        $db->method('rollBack')->willReturnCallback(static function () use ($pdo) { $pdo->rollBack(); });
        return $db;
    }
}
