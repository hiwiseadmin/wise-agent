<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\bridge\AdapterRegistry;
use wise\agent\bridge\NodeRegistry;
use wise\agent\bridge\PluginAiDiscovery;
use wise\agent\contract\AdapterProviderInterface;
use wise\agent\contract\AiClientInterface;
use wise\agent\contract\NodeProviderInterface;
use wise\agent\contract\RequestContext;
use wise\agent\contract\ToolInterface;
use wise\agent\contract\ToolProviderInterface;
use wise\agent\contract\WorkflowContext;
use wise\agent\tool\ToolRegistry;


class TestToolProvider_D1scovery implements ToolProviderInterface
{
    public function getNamespace(): string
    {
        return 'plugin:test';
    }

    public function getTools(): array
    {
        return [
            new class implements ToolInterface {
                public function getName(): string
                { return 'hello'; }
                public function getDescription(): string
                { return 'Say hello'; }
                public function getParameters(): array
                { return ['type' => 'object', 'properties' => []]; }
                public function execute(array $arguments): string
                { return json_encode(['hello' => 'world']); }
                public function getPermission(): string
                { return ''; }
                public function requireConfirmation(): bool
                { return false; }
                public function getAccess(): string
                { return \wise\agent\security\CapabilityAccess::READ; }
                public function getRiskLevel(): string
                { return \wise\agent\security\CapabilityAccess::RISK_LOW; }
            },
        ];
    }

    public function execute(string $toolName, array $params, RequestContext $context): mixed
    {
        return ['ok' => true];
    }

    public function getMetadata(): array
    {
        return ['label' => 'Test Provider', 'version' => '1.0'];
    }
}

class TestNodeProvider_D1scovery implements NodeProviderInterface
{
    public function getNamespace(): string
    {
        return 'plugin:test';
    }

    public function getNodes(): array
    {
        return [
            [
                'type'          => 'delay',
                'label'         => 'Delay',
                'description'   => 'Pause execution',
                'config_schema' => [
                    'type'       => 'object',
                    'properties' => ['seconds' => ['type' => 'integer']],
                ],
                'group' => 'integration',
            ],
        ];
    }

    public function run(string $nodeType, array $config, array $input, WorkflowContext $context): array
    {
        return ['data' => ['slept' => $config['seconds'] ?? 0], 'error' => null];
    }

    public function getMetadata(): array
    {
        return ['label' => 'Test Node'];
    }
}

class TestAdapterProvider_D1scovery implements AdapterProviderInterface
{
    public function getNamespace(): string
    {
        return 'plugin:test';
    }

    public function adapterInfo(): array
    {
        return [
            'id'              => 'plugin:test.mock_model',
            'label'           => 'Mock Model',
            'description'     => 'A mock adapter for testing',
            'provider'        => 'mock',
            'model'           => 'mock-gpt',
            'supports_stream' => true,
            'supports_tools'  => true,
            'config_schema'   => ['type' => 'object', 'properties' => []],
        ];
    }

    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        return ['content' => 'mock response', 'tool_calls' => [], 'usage' => [], 'finish_reason' => 'stop'];
    }

    public function chatStream(array $messages, array $tools = [], array $options = []): \Generator
    {
        yield ['type' => 'token', 'content' => 'mock'];
        yield ['type' => 'finish', 'reason' => 'stop'];
    }

    public function getProviderName(): string { return 'mock'; }
    public function getModelName(): string { return 'mock-gpt'; }
    public function supports(string $feature): bool { return true; }
    public function listModels(): array { return ['mock-gpt']; }
    public function countTokens(string|array $input): int { return 0; }
    public function estimateCost(int $promptTokens, int $completionTokens): float { return 0.0; }
}

class ComposerToolProvider_D1scovery extends TestToolProvider_D1scovery
{
    public function getNamespace(): string
    {
        return 'composer:wiseadmin/wise-test';
    }
}

class ComposerNodeProvider_D1scovery extends TestNodeProvider_D1scovery
{
    public function getNamespace(): string
    {
        return 'composer:wiseadmin/wise-full';
    }
}

class ComposerAdapterProvider_D1scovery extends TestAdapterProvider_D1scovery
{
    public function getNamespace(): string
    {
        return 'composer:wiseadmin/wise-full';
    }
}



class PluginAiDiscoveryTest extends TestCase
{
    protected string $tempDir;
    protected \think\App $app;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise-agent-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'wise-agent', 0755, true);

        $config = $this->getMockBuilder(\think\Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $config->method('get')->willReturnCallback(function ($key, $default = null) {
            return $default;
        });

        $this->app = $this->getMockBuilder(\think\App::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRootPath', 'getRuntimePath', 'make', '__get'])
            ->getMock();
        $this->app->method('getRootPath')->willReturn($this->tempDir . DIRECTORY_SEPARATOR);
        $this->app->method('getRuntimePath')->willReturn(
            $this->tempDir . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR
        );
        $this->app->method('__get')->willReturnCallback(function (string $name) use ($config) {
            if ($name === 'config') {
                return $config;
            }
            return null;
        });

        $this->app->method('make')->willReturnCallback(function (string $class) {
            if (class_exists($class)) {
                return new $class();
            }
            return null;
        });

        ToolRegistry::reset();
        NodeRegistry::reset();
        AdapterRegistry::reset();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);

        ToolRegistry::reset();
        NodeRegistry::reset();
        AdapterRegistry::reset();
    }


    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    protected function createManifest(string $pluginName, array $aiProviders): string
    {
        $pluginDir = $this->tempDir . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $pluginName;
        mkdir($pluginDir, 0755, true);

        $manifest = [
            'name'         => "Test Plugin {$pluginName}",
            'ident'        => $pluginName,
            'author'       => 'Test',
            'version'      => '1.0.0',
            'description'  => 'Test plugin',
            'ai_providers' => $aiProviders,
        ];

        $manifestFile = $pluginDir . DIRECTORY_SEPARATOR . 'manifest.json';
        file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT));
        return $manifestFile;
    }

    protected function createComposerInstalled(array $packages): string
    {
        $vendorDir = $this->tempDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer';
        mkdir($vendorDir, 0755, true);

        $installedFile = $vendorDir . DIRECTORY_SEPARATOR . 'installed.json';
        file_put_contents($installedFile, json_encode(['packages' => $packages], JSON_PRETTY_PRINT));
        return $installedFile;
    }

    protected function createDiscovery(): PluginAiDiscovery
    {
        return new PluginAiDiscovery($this->app);
    }

    /**
     * Create a discovery instance and clear its cache.
     * Useful for testing that cache is re-populated after clearing.
     */
    protected function clearCache(): void
    {
        $discovery = $this->createDiscovery();
        $ref = new \ReflectionClass(PluginAiDiscovery::class);
        $cacheProp = $ref->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue($discovery);
        $cache->clear();
    }


    public function testDiscoverStandardPluginsWithToolProvider(): void
    {
        $this->createManifest('testplugin', [
            'tool' => TestToolProvider_D1scovery::class,
        ]);

        $discovery = $this->createDiscovery();
        $stats     = $discovery->discover();

        $this->assertEquals(1, $stats['tools'], 'Should discover 1 tool provider');
        $this->assertEquals(0, $stats['nodes']);
        $this->assertEquals(0, $stats['adapters']);
        $this->assertEmpty($stats['errors']);

        $registry = ToolRegistry::instance();
        $this->assertTrue($registry->has('plugin:test.hello'), 'Tool should be registered with namespace prefix');

        $namespaces = $discovery->getDiscoveredNamespaces();
        $this->assertArrayHasKey('plugin:test', $namespaces);
        $this->assertContains(TestToolProvider_D1scovery::class, $namespaces['plugin:test']['tool_providers']);
    }

    public function testDiscoverStandardPluginsWithNodeProvider(): void
    {
        $this->createManifest('testplugin', [
            'node' => TestNodeProvider_D1scovery::class,
        ]);

        $discovery = $this->createDiscovery();
        $stats     = $discovery->discover();

        $this->assertEquals(0, $stats['tools']);
        $this->assertEquals(1, $stats['nodes'], 'Should discover 1 node provider');
        $this->assertEquals(0, $stats['adapters']);
        $this->assertEmpty($stats['errors']);

        $registry = NodeRegistry::instance();
        $this->assertTrue($registry->hasProvider('plugin:test'), 'Node provider should be registered');
    }

    public function testDiscoverStandardPluginsWithAdapterProvider(): void
    {
        $this->createManifest('testplugin', [
            'adapter' => TestAdapterProvider_D1scovery::class,
        ]);

        $discovery = $this->createDiscovery();
        $stats     = $discovery->discover();

        $this->assertEquals(0, $stats['tools']);
        $this->assertEquals(0, $stats['nodes']);
        $this->assertEquals(1, $stats['adapters'], 'Should discover 1 adapter provider');
        $this->assertEmpty($stats['errors']);

        $registry = AdapterRegistry::instance();
        $this->assertTrue($registry->hasAdapter('plugin:test'), 'Adapter provider should be registered');
    }

    public function testDiscoverStandardPluginsWithAllProviders(): void
    {
        $this->createManifest('fullplugin', [
            'tool'    => TestToolProvider_D1scovery::class,
            'node'    => TestNodeProvider_D1scovery::class,
            'adapter' => TestAdapterProvider_D1scovery::class,
        ]);

        $discovery = $this->createDiscovery();
        $stats     = $discovery->discover();

        $this->assertEquals(1, $stats['tools']);
        $this->assertEquals(1, $stats['nodes']);
        $this->assertEquals(1, $stats['adapters']);
        $this->assertEmpty($stats['errors']);

        $this->assertTrue(ToolRegistry::instance()->has('plugin:test.hello'));
        $this->assertTrue(NodeRegistry::instance()->hasProvider('plugin:test'));
        $this->assertTrue(AdapterRegistry::instance()->hasAdapter('plugin:test'));
    }

    public function testDiscoverStandardPluginsWithMultiplePlugins(): void
    {
        $this->createManifest('plugin_a', [
            'tool' => TestToolProvider_D1scovery::class,
        ]);
        $this->createManifest('plugin_b', [
            'node' => TestNodeProvider_D1scovery::class,
        ]);

        $discovery = $this->createDiscovery();
        $stats     = $discovery->discover();

        $this->assertEquals(2, $stats['tools'] + $stats['nodes'] + $stats['adapters'],
            'Total should be 2 providers across both plugins');

        $namespaces = $discovery->getDiscoveredNamespaces();
        $this->assertCount(1, $namespaces);
        $this->assertArrayHasKey('plugin:test', $namespaces);
    }


    public function testDiscoverWithNoPlugins(): void
    {
        $discovery = $this->createDiscovery();
        $stats     = $discovery->discover();

        $this->assertEquals(0, $stats['tools']);
        $this->assertEquals(0, $stats['nodes']);
        $this->assertEquals(0, $stats['adapters']);
        $this->assertEmpty($stats['errors']);
    }

    public function testDiscoverWithNoPluginDirectory(): void
    {
        $pluginDir = $this->tempDir . DIRECTORY_SEPARATOR . 'plugin';
        if (is_dir($pluginDir)) {
            $this->removeDir($pluginDir);
        }

        $discovery = $this->createDiscovery();
        $stats     = $discovery->discover();

        $this->assertEquals(0, $stats['tools']);
        $this->assertEmpty($stats['errors']);
    }

    public function testDiscoverWithInvalidManifestJson(): void
    {
        $pluginDir = $this->tempDir . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 'badplugin';
        mkdir($pluginDir, 0755, true);
        file_put_contents($pluginDir . DIRECTORY_SEPARATOR . 'manifest.json', '{invalid json!!!}');

        $discovery = $this->createDiscovery();
        $stats     = $discovery->discover();

        $this->assertEquals(0, $stats['tools']);
        $this->assertEmpty($stats['errors'], 'Invalid JSON should be silently skipped');
    }

    public function testDiscoverWithMissingProviderClass(): void
    {
        $this->createManifest('ghostplugin', [
            'tool' => 'NonExistent\\Ghost\\ToolProvider',
        ]);

        $discovery = $this->createDiscovery();
        $stats     = $discovery->discover();

        $this->assertEquals(0, $stats['tools']);
        $this->assertNotEmpty($stats['errors'], 'Should record validation error');
        $this->assertStringContainsString('validation failed', $stats['errors'][0]);
    }

    public function testDiscoverWithProviderThatThrowsException(): void
    {
        $this->createManifest('crashplugin', [
            'tool' => TestToolProvider_D1scovery::class,
        ]);

        $config = $this->getMockBuilder(\think\Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $config->method('get')->willReturnCallback(function ($key, $default = null) {
            return $default;
        });
        $this->app = $this->getMockBuilder(\think\App::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRootPath', 'getRuntimePath', 'make', '__get'])
            ->getMock();
        $this->app->method('getRootPath')->willReturn($this->tempDir . DIRECTORY_SEPARATOR);
        $this->app->method('getRuntimePath')->willReturn(
            $this->tempDir . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR
        );
        $this->app->method('__get')->willReturnCallback(function (string $name) use ($config) {
            if ($name === 'config') {
                return $config;
            }
            return null;
        });
        $this->app->method('make')->willThrowException(new \RuntimeException('Boom!'));

        $discovery = $this->createDiscovery();
        $stats     = $discovery->discover();

        $this->assertEquals(0, $stats['tools']);
        $this->assertNotEmpty($stats['errors']);
        $this->assertStringContainsString('Boom!', $stats['errors'][0]);
    }

    public function testDiscoverWithEmptyAiProviders(): void
    {
        $this->createManifest('noproviders', []);

        $discovery = $this->createDiscovery();
        $stats     = $discovery->discover();

        $this->assertEquals(0, $stats['tools']);
        $this->assertEmpty($stats['errors']);
    }


    public function testDiscoverComposerPackagesWithToolProviders(): void
    {
        $this->createComposerInstalled([
            [
                'name'  => 'wiseadmin/wise-test',
                'extra' => [
                    'think-ai' => [
                        'tool_providers' => [ComposerToolProvider_D1scovery::class],
                    ],
                ],
            ],
        ]);

        $discovery = $this->createDiscovery();
        $stats     = $discovery->discover();

        $this->assertEquals(1, $stats['tools']);
        $this->assertEmpty($stats['errors']);

        $namespaces = $discovery->getDiscoveredNamespaces();
        $this->assertArrayHasKey('composer:wiseadmin/wise-test', $namespaces);
    }

    public function testDiscoverComposerPackagesWithAllProviders(): void
    {
        $this->createComposerInstalled([
            [
                'name'  => 'wiseadmin/wise-full',
                'extra' => [
                    'think-ai' => [
                        'tool_providers'    => [ComposerToolProvider_D1scovery::class],
                        'node_providers'    => [ComposerNodeProvider_D1scovery::class],
                        'adapter_providers' => [ComposerAdapterProvider_D1scovery::class],
                    ],
                ],
            ],
        ]);

        $discovery = $this->createDiscovery();
        $stats     = $discovery->discover();

        $this->assertEquals(1, $stats['tools']);
        $this->assertEquals(1, $stats['nodes']);
        $this->assertEquals(1, $stats['adapters']);
        $this->assertEmpty($stats['errors']);
    }

    public function testDiscoverComposerWithoutThinkAiExtra(): void
    {
        $this->createComposerInstalled([
            [
                'name'  => 'some/normal-package',
                'extra' => ['other' => 'value'],
            ],
        ]);

        $discovery = $this->createDiscovery();
        $stats     = $discovery->discover();

        $this->assertEquals(0, $stats['tools']);
        $this->assertEmpty($stats['errors']);
    }


    public function testDiscoverCachesResults(): void
    {
        $this->createManifest('testplugin', [
            'tool' => TestToolProvider_D1scovery::class,
        ]);

        $discovery1 = $this->createDiscovery();
        $stats1     = $discovery1->discover();
        $this->assertEquals(1, $stats1['tools']);

        $cacheFile = $this->tempDir . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'wise-agent' . DIRECTORY_SEPARATOR . 'provider_cache.php';
        $this->assertFileExists($cacheFile, 'Cache file should be written');

        ToolRegistry::reset();
        $discovery2 = $this->createDiscovery();
        $stats2     = $discovery2->discover();

        $this->assertEquals(1, $stats2['tools'], 'Should restore from cache');
        $this->assertTrue(ToolRegistry::instance()->has('plugin:test.hello'),
            'Tool should be restored from cache');

        $namespaces = $discovery2->getDiscoveredNamespaces();
        $this->assertArrayHasKey('plugin:test', $namespaces);
        $this->assertContains(TestToolProvider_D1scovery::class,
            $namespaces['plugin:test']['tool_providers']);
    }

    public function testDiscoverForceRefreshBypassesCache(): void
    {
        $this->createManifest('testplugin', [
            'tool' => TestToolProvider_D1scovery::class,
        ]);

        $discovery1 = $this->createDiscovery();
        $stats1     = $discovery1->discover(true);
        $this->assertEquals(1, $stats1['tools']);

        $manifestFile = $this->tempDir . DIRECTORY_SEPARATOR . 'plugin'
            . DIRECTORY_SEPARATOR . 'testplugin' . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = json_decode(file_get_contents($manifestFile), true);
        $manifest['ai_providers']['node'] = TestNodeProvider_D1scovery::class;
        file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT));

        ToolRegistry::reset();
        NodeRegistry::reset();
        $discovery2 = $this->createDiscovery();
        $stats2     = $discovery2->discover(true);

        $this->assertEquals(1, $stats2['tools']);
        $this->assertEquals(1, $stats2['nodes'], 'Force refresh should discover new node provider');
    }

    public function testDiscoverWithCacheInvalidated(): void
    {
        $this->createManifest('testplugin', [
            'tool' => TestToolProvider_D1scovery::class,
        ]);

        $discovery1 = $this->createDiscovery();
        $stats1     = $discovery1->discover();
        $this->assertEquals(1, $stats1['tools']);

        $this->clearCache();

        ToolRegistry::reset();
        $discovery2 = $this->createDiscovery();
        $stats2     = $discovery2->discover();

        $this->assertEquals(1, $stats2['tools'],
            'After invalidation, should re-scan and find provider');

        $cacheFile = $this->tempDir . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'wise-agent' . DIRECTORY_SEPARATOR . 'provider_cache.php';
        $this->assertFileExists($cacheFile);
    }

    public function testLoadFromCacheWithMissingClass(): void
    {
        $this->createManifest('testplugin', [
            'tool' => TestToolProvider_D1scovery::class,
        ]);

        $discovery1 = $this->createDiscovery();
        $stats1     = $discovery1->discover();
        $this->assertEquals(1, $stats1['tools']);

        $cacheFile = $this->tempDir . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'wise-agent' . DIRECTORY_SEPARATOR . 'provider_cache.php';
        $cachedData = json_decode((string) file_get_contents($cacheFile), true);
        $cachedData['namespaces']['plugin:test']['tool_providers'][] = 'Phantom\\Ghost\\Provider';
        file_put_contents($cacheFile, json_encode($cachedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        ToolRegistry::reset();
        $discovery2 = $this->createDiscovery();
        $stats2     = $discovery2->discover();

        $this->assertEquals(1, $stats2['tools'],
            'Valid provider should still be restored');
        $this->assertNotEmpty($stats2['errors'],
            'Should have error for missing class');
        $this->assertStringContainsString('not found', $stats2['errors'][0]);
    }

    public function testDiscoverWithEmptyCachePayload(): void
    {
        $discovery1 = $this->createDiscovery();
        $stats1     = $discovery1->discover();
        $this->assertEquals(0, $stats1['tools']);

        $cacheFile = $this->tempDir . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'wise-agent' . DIRECTORY_SEPARATOR . 'provider_cache.php';
        $this->assertFileExists($cacheFile, 'Empty result should also be cached');

        $discovery2 = $this->createDiscovery();
        $stats2     = $discovery2->discover();

        $this->assertEquals(0, $stats2['tools']);
        $this->assertEmpty($stats2['errors']);
    }


    public function testGetStats(): void
    {
        $this->createManifest('testplugin', [
            'tool' => TestToolProvider_D1scovery::class,
        ]);

        $discovery = $this->createDiscovery();
        $discovery->discover();

        $stats = $discovery->getStats();
        $this->assertArrayHasKey('tools', $stats);
        $this->assertArrayHasKey('nodes', $stats);
        $this->assertArrayHasKey('adapters', $stats);
        $this->assertArrayHasKey('errors', $stats);
        $this->assertEquals(1, $stats['tools']);
    }

    public function testGetDiscoveredNamespaces(): void
    {
        $this->createManifest('testplugin', [
            'tool'    => TestToolProvider_D1scovery::class,
            'adapter' => TestAdapterProvider_D1scovery::class,
        ]);

        $discovery = $this->createDiscovery();
        $discovery->discover();

        $namespaces = $discovery->getDiscoveredNamespaces();
        $this->assertArrayHasKey('plugin:test', $namespaces);
        $this->assertCount(1, $namespaces['plugin:test']['tool_providers']);
        $this->assertContains(TestToolProvider_D1scovery::class,
            $namespaces['plugin:test']['tool_providers']);
        $this->assertCount(1, $namespaces['plugin:test']['adapter_providers']);
        $this->assertContains(TestAdapterProvider_D1scovery::class,
            $namespaces['plugin:test']['adapter_providers']);
    }

    public function testStatsResetBetweenDiscoveries(): void
    {
        $this->createManifest('testplugin', [
            'tool' => TestToolProvider_D1scovery::class,
        ]);

        $discovery = $this->createDiscovery();

        $stats1 = $discovery->discover();
        $this->assertEquals(1, $stats1['tools']);

        $stats2 = $discovery->discover();
        $this->assertEquals(1, $stats2['tools']);
        $this->assertEmpty($stats2['errors']);
    }


    public function testRegisterToolProviderClearsPreviousTools(): void
    {
        $this->createManifest('testplugin', [
            'tool' => TestToolProvider_D1scovery::class,
        ]);

        $discovery = $this->createDiscovery();
        $discovery->discover();

        $registry = ToolRegistry::instance();
        $this->assertTrue($registry->has('plugin:test.hello'));

        ToolRegistry::reset();
        $discovery2 = $this->createDiscovery();
        $discovery2->discover();

        $registry2 = ToolRegistry::instance();
        $this->assertTrue($registry2->has('plugin:test.hello'),
            'Tool should still be registered after re-discovery');
    }

    public function testToolExecutionFromDiscoveredProvider(): void
    {
        $this->createManifest('testplugin', [
            'tool' => TestToolProvider_D1scovery::class,
        ]);

        $discovery = $this->createDiscovery();
        $discovery->discover();

        $registry = ToolRegistry::instance();
        $result   = $registry->execute('plugin:test.hello', []);
        $this->assertStringContainsString('"hello"', $result);
    }


    public function testNodeProviderRegistration(): void
    {
        $this->createManifest('testplugin', [
            'node' => TestNodeProvider_D1scovery::class,
        ]);

        $discovery = $this->createDiscovery();
        $discovery->discover();

        $registry    = NodeRegistry::instance();
        $definitions = $registry->getNodeDefinitions();

        $this->assertCount(1, $definitions);
        $this->assertEquals('plugin:test:delay', $definitions[0]->getFullType());
    }


    public function testAdapterProviderRegistration(): void
    {
        $this->createManifest('testplugin', [
            'adapter' => TestAdapterProvider_D1scovery::class,
        ]);

        $discovery = $this->createDiscovery();
        $discovery->discover();

        $registry = AdapterRegistry::instance();
        $this->assertTrue($registry->hasAdapter('plugin:test'));

        $infoList = $registry->getAdapterInfoList();
        $this->assertCount(1, $infoList);
        $this->assertEquals('plugin:test.mock_model', $infoList[0]['id']);
    }
}
