<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Indexer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class IndexerExclusionTest extends TestCase {
    public function testExcludedFoldersAndDescendantsAreActuallySkipped(): void {
        $config = $this->createMock(AppConfig::class);
        $config->expects(self::once())
            ->method('get')
            ->with('exclude_paths')
            ->willReturn('Private, Projects/Secret');

        $reflection = new ReflectionClass(Indexer::class);
        $indexer = $reflection->newInstanceWithoutConstructor();
        $configProperty = $reflection->getProperty('config');
        $configProperty->setValue($indexer, $config);

        $parse = $reflection->getMethod('parseExcludePaths');
        $excludePaths = $parse->invoke($indexer);
        self::assertSame(['private', 'projects/secret'], $excludePaths);

        $isExcluded = $reflection->getMethod('isPathExcluded');
        self::assertTrue($isExcluded->invoke($indexer, 'Private', $excludePaths));
        self::assertTrue($isExcluded->invoke($indexer, 'Private/Documents/resume.pdf', $excludePaths));
        self::assertTrue($isExcluded->invoke($indexer, 'projects/secret/api-keys.txt', $excludePaths));
        self::assertFalse($isExcluded->invoke($indexer, 'Privates', $excludePaths));
        self::assertFalse($isExcluded->invoke($indexer, 'PrivateFolder/file.txt', $excludePaths));
        self::assertFalse($isExcluded->invoke($indexer, 'Projects/SecretlyPublic', $excludePaths));
    }
}
