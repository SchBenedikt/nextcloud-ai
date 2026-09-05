<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCP\TaskProcessing\ISynchronousProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for EVA TaskProcessing providers.
 *
 * Validates that every registered EVA provider meets the input/output contracts
 * expected by Nextcloud Assistant. Malformed metadata can cause the Assistant
 * frontend to fail while parsing task types.
 *
 * Affected providers: TextToTextChatProvider, AgentInteractionProvider,
 * TextToTextChatWithToolsProvider, EvaTextToTextProvider, EvaSummaryProvider,
 * EvaHeadlineProvider, EvaTopicsProvider, EvaTranslateProvider,
 * EvaReformulateProvider, EvaProofreadProvider, EvaReformatProvider,
 * EvaChangeToneProvider, EvaContextWriteProvider
 */
class TaskProcessingContractTest extends TestCase {

    protected function setUp(): void {
        // These contract tests need the real OCP interface definitions from a
        // Nextcloud installation (lib/public). In CI without Nextcloud the
        // tests are skipped instead of failing.
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('OCP interfaces not available (set NEXTCLOUD_ROOT to run).');
        }
    }

    /**
     * Returns all EVA TaskProcessing provider class names.
     * @return list<class-string<ISynchronousProvider>>
     */
    private function providerClasses(): array {
        return [
            \OCA\EvaAi\TaskProcessing\TextToTextChatProvider::class,
            \OCA\EvaAi\TaskProcessing\AgentInteractionProvider::class,
            \OCA\EvaAi\TaskProcessing\TextToTextChatWithToolsProvider::class,
            \OCA\EvaAi\TaskProcessing\EvaTextToTextProvider::class,
            \OCA\EvaAi\TaskProcessing\EvaSummaryProvider::class,
            \OCA\EvaAi\TaskProcessing\EvaHeadlineProvider::class,
            \OCA\EvaAi\TaskProcessing\EvaTopicsProvider::class,
            \OCA\EvaAi\TaskProcessing\EvaTranslateProvider::class,
            \OCA\EvaAi\TaskProcessing\EvaReformulateProvider::class,
            \OCA\EvaAi\TaskProcessing\EvaProofreadProvider::class,
            \OCA\EvaAi\TaskProcessing\EvaReformatProvider::class,
            \OCA\EvaAi\TaskProcessing\EvaChangeToneProvider::class,
            \OCA\EvaAi\TaskProcessing\EvaContextWriteProvider::class,
        ];
    }

    public function testProviderIdsAreUnique(): void {
        $ids = [];
        foreach ($this->providerClasses() as $class) {
            $id = (new \ReflectionClass($class))->getMethod('getId')->invoke(
                $this->createMockProvider($class)
            );
            $this->assertNotEmpty($id, "Provider $class has empty ID");
            $this->assertNotContains($id, $ids, "Duplicate provider ID: $id");
            $ids[] = $id;
        }
    }

    public function testProviderIdsStartWithEvaPrefix(): void {
        foreach ($this->providerClasses() as $class) {
            $id = (new \ReflectionClass($class))->getMethod('getId')->invoke(
                $this->createMockProvider($class)
            );
            $this->assertStringStartsWith('eva_ai', $id, "Provider $class ID should start with 'eva_ai': $id");
        }
    }

    public function testProviderNamesAreNotEmpty(): void {
        foreach ($this->providerClasses() as $class) {
            $name = (new \ReflectionClass($class))->getMethod('getName')->invoke(
                $this->createMockProvider($class)
            );
            $this->assertNotEmpty($name, "Provider $class has empty name");
        }
    }

    public function testTaskTypeIdsAreValid(): void {
        $validTypes = [
            'core:text2text:chat',
            'core:text2text',
            'core:text2text:summary',
            'core:text2text:headline',
            'core:text2text:topics',
            'core:text2text:translate',
            'core:text2text:reformulation',
            'core:text2text:proofread',
            'core:text2text:reformatparagraphs',
            'core:text2text:changetone',
            'core:contextagent:interaction',
            'core:text2text:chatwithtools',
            'core:contextwrite',
        ];

        foreach ($this->providerClasses() as $class) {
            $typeId = (new \ReflectionClass($class))->getMethod('getTaskTypeId')->invoke(
                $this->createMockProvider($class)
            );
            $this->assertNotEmpty($typeId, "Provider $class has empty task type ID");
            $this->assertContains(
                $typeId,
                $validTypes,
                "Provider $class has unexpected task type ID: $typeId"
            );
        }
    }

    public function testExpectedRuntimesArePositive(): void {
        foreach ($this->providerClasses() as $class) {
            $runtime = (new \ReflectionClass($class))->getMethod('getExpectedRuntime')->invoke(
                $this->createMockProvider($class)
            );
            $this->assertGreaterThan(0, $runtime, "Provider $class should have positive expected runtime");
            $this->assertLessThanOrEqual(600, $runtime, "Provider $class runtime seems too high: $runtime seconds");
        }
    }

    public function testInputShapesAreArrays(): void {
        foreach ($this->providerClasses() as $class) {
            $ref = new \ReflectionClass($class);
            $provider = $this->createMockProvider($class);

            // getInputShapeEnumValues must return array
            $this->assertIsArray(
                $ref->getMethod('getInputShapeEnumValues')->invoke($provider),
                "$class::getInputShapeEnumValues() must return array"
            );

            // getInputShapeDefaults must return array
            $this->assertIsArray(
                $ref->getMethod('getInputShapeDefaults')->invoke($provider),
                "$class::getInputShapeDefaults() must return array"
            );

            // getOptionalInputShape must return array
            $this->assertIsArray(
                $ref->getMethod('getOptionalInputShape')->invoke($provider),
                "$class::getOptionalInputShape() must return array"
            );
        }
    }

    public function testOutputShapesAreArrays(): void {
        foreach ($this->providerClasses() as $class) {
            $ref = new \ReflectionClass($class);
            $provider = $this->createMockProvider($class);

            // getOutputShapeEnumValues must return array
            $this->assertIsArray(
                $ref->getMethod('getOutputShapeEnumValues')->invoke($provider),
                "$class::getOutputShapeEnumValues() must return array"
            );

            // getOptionalOutputShape must return array
            $this->assertIsArray(
                $ref->getMethod('getOptionalOutputShape')->invoke($provider),
                "$class::getOptionalOutputShape() must return array"
            );
        }
    }

    /**
     * Helper to create a mock provider with only the required dependencies.
     */
    private function createMockProvider(string $class): ISynchronousProvider {
        $ref = new \ReflectionClass($class);
        $constructor = $ref->getConstructor();

        if ($constructor === null) {
            return $ref->newInstance();
        }

        $params = $constructor->getParameters();
        $args = [];

        foreach ($params as $param) {
            $type = $param->getType();
            if ($type === null) {
                $args[] = null;
                continue;
            }

            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';

            // Create mocks for common dependencies
            if ($typeName === \OCP\IL10N::class) {
                $mock = $this->createMock(\OCP\IL10N::class);
                $mock->method('t')->willReturnCallback(fn(string $s) => $s);
                $args[] = $mock;
            } elseif ($typeName === \Psr\Log\LoggerInterface::class) {
                $args[] = $this->createMock(\Psr\Log\LoggerInterface::class);
            } elseif ($typeName === \OCA\EvaAi\Service\AppConfig::class) {
                $args[] = $this->createMock(\OCA\EvaAi\Service\AppConfig::class);
            } elseif ($typeName === \OCA\EvaAi\Service\Ollama::class) {
                $args[] = $this->createMock(\OCA\EvaAi\Service\Ollama::class);
            } elseif ($typeName === \OCP\ICacheFactory::class) {
                $args[] = $this->createMock(\OCP\ICacheFactory::class);
            } elseif ($typeName === \OCA\EvaAi\Service\ActionExecutor::class) {
                $args[] = $this->createMock(\OCA\EvaAi\Service\ActionExecutor::class);
            } elseif ($typeName === \OCA\EvaAi\Service\AgentStore::class) {
                $args[] = $this->createMock(\OCA\EvaAi\Service\AgentStore::class);
            } elseif ($typeName === \OCA\EvaAi\Service\Searcher::class) {
                $args[] = $this->createMock(\OCA\EvaAi\Service\Searcher::class);
            } elseif ($typeName === \OCA\EvaAi\Service\TalkContextReader::class) {
                $args[] = $this->createMock(\OCA\EvaAi\Service\TalkContextReader::class);
            } elseif ($typeName === \OCP\Files\IRootFolder::class) {
                $args[] = $this->createMock(\OCP\Files\IRootFolder::class);
            } elseif (interface_exists($typeName)) {
                $args[] = $this->createMock($typeName);
            } elseif (class_exists($typeName)) {
                $args[] = $this->createMock($typeName);
            } else {
                $args[] = null;
            }
        }

        return $ref->newInstanceArgs($args);
    }
}
