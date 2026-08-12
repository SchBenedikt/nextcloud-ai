<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\KnowledgeInitializer;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class KnowledgeInitializerTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    /** @return array{0:AppConfig,1:\stdClass} */
    private function config(): array {
        $state = new \stdClass();
        $state->values = [];
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturnCallback(
            static function (string $user, string $app, string $key, string $default) use ($state): string {
                return $state->values[$user][$key] ?? $default;
            }
        );
        $config->method('setUserValue')->willReturnCallback(
            static function (string $user, string $app, string $key, string $value) use ($state): void {
                $state->values[$user][$key] = $value;
            }
        );
        return [new AppConfig($config), $state];
    }

    public function testFirstRunCreatesPersonalProfileSection(): void {
        [$config, $state] = $this->config();
        $user = $this->createMock(IUser::class);
        $user->method('getDisplayName')->willReturn('Benedikt Schäcner');
        $user->method('getEMailAddress')->willReturn('benedikt@example.test');
        $users = $this->createMock(IUserManager::class);
        $users->expects(self::once())->method('get')->with('alice')->willReturn($user);
        $file = $this->createMock(File::class);
        $home = $this->createMock(Folder::class);
        $home->expects(self::once())->method('nodeExists')->with('KNOWLEDGE.md')->willReturn(false);
        $home->expects(self::once())->method('newFile')->with(
            'KNOWLEDGE.md',
            self::callback(static fn(string $content): bool => str_contains($content, '<!-- eva_ai:profile-initialized -->')
                && str_contains($content, 'Benedikt Schäcner')
                && str_contains($content, 'benedikt@example.test'))
        )->willReturn($file);
        $root = $this->createMock(IRootFolder::class);
        $root->expects(self::once())->method('getUserFolder')->with('alice')->willReturn($home);

        (new KnowledgeInitializer($config, $root, $users))->ensureInitialized('alice');

        self::assertSame('1', $state->values['alice']['knowledge_initialized'] ?? null);
    }

    public function testExistingKnowledgeIsPreservedAndProfileIsAppendedOnce(): void {
        [$config, $state] = $this->config();
        $user = $this->createMock(IUser::class);
        $user->method('getDisplayName')->willReturn('Alice');
        $user->method('getEMailAddress')->willReturn(null);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($user);
        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn("# My notes\n- Keep this line\n");
        $file->expects(self::once())->method('putContent')->with(self::callback(
            static fn(string $content): bool => str_starts_with($content, "# My notes\n- Keep this line")
                && substr_count($content, '<!-- eva_ai:profile-initialized -->') === 1
        ));
        $home = $this->createMock(Folder::class);
        $home->method('nodeExists')->with('KNOWLEDGE.md')->willReturn(true);
        $home->method('get')->with('KNOWLEDGE.md')->willReturn($file);
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($home);

        $initializer = new KnowledgeInitializer($config, $root, $users);
        $initializer->ensureInitialized('alice');
        $initializer->ensureInitialized('alice');

        self::assertSame('1', $state->values['alice']['knowledge_initialized'] ?? null);
    }

    public function testEmptyExistingKnowledgeFileIsUpdatedRatherThanRecreated(): void {
        [$config, $state] = $this->config();
        $user = $this->createMock(IUser::class);
        $user->method('getDisplayName')->willReturn('Alice');
        $user->method('getEMailAddress')->willReturn(null);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($user);
        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn('');
        $file->expects(self::once())->method('putContent')->with(self::stringContains('eva_ai:profile-initialized'));
        $home = $this->createMock(Folder::class);
        $home->method('nodeExists')->with('KNOWLEDGE.md')->willReturn(true);
        $home->method('get')->with('KNOWLEDGE.md')->willReturn($file);
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($home);

        (new KnowledgeInitializer($config, $root, $users))->ensureInitialized('alice');

        self::assertSame('1', $state->values['alice']['knowledge_initialized'] ?? null);
    }

    public function testInitializedUserDoesNotReadProfileOrFilesystem(): void {
        [$config, $state] = $this->config();
        $config->setUserId('alice');
        $config->set('knowledge_initialized', '1');
        $users = $this->createMock(IUserManager::class);
        $users->expects(self::never())->method('get');
        $root = $this->createMock(IRootFolder::class);
        $root->expects(self::never())->method('getUserFolder');

        (new KnowledgeInitializer($config, $root, $users))->ensureInitialized('alice');

        self::assertSame('1', $state->values['alice']['knowledge_initialized']);
    }
}
