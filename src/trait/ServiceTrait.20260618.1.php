<?php
declare(strict_types=1);

namespace wise\agent\trait;

use think\facade\Config;

/**
 * WiseAdmin package Service provider shared trait
 *
 * Provides config merging and publishing utilities for wise-* packages.
 */
trait ServiceTrait
{
    /**
     * Merge package default config into global config
     */
    protected function mergeConfig(string $configName): array
    {
        $defaultConfig = $this->loadPackageDefaultConfig($configName);
        if ($defaultConfig === null) {
            return [];
        }

        $appConfig = Config::get($configName, []);
        $mergedConfig = $this->arrayMergeRecursive($defaultConfig, $appConfig);
        Config::set($mergedConfig, $configName);
        return $mergedConfig;
    }

    /**
     * Publish config file to application config directory
     */
    protected function publishConfigFile(string $configName): bool
    {
        $packageConfig = $this->getPackageConfigFilePath($configName);
        $appConfig = $this->app->getConfigPath() . $configName . '.php';

        if (!file_exists($packageConfig) || file_exists($appConfig)) {
            return false;
        }

        $targetDir = dirname($appConfig);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        return copy($packageConfig, $appConfig) !== false;
    }

    /**
     * Recursively merge arrays (override takes priority over base)
     */
    protected function arrayMergeRecursive(array $base, array $override): array
    {
        $result = $base;

        foreach ($override as $key => $value) {
            if (is_int($key)) {
                $result[] = $value;
            } elseif (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                $result[$key] = $this->arrayMergeRecursive($result[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Load package default config file
     */
    protected function loadPackageDefaultConfig(string $configName): ?array
    {
        $filePath = $this->getPackageConfigFilePath($configName);

        if (!file_exists($filePath)) {
            return null;
        }

        return require $filePath;
    }

    /**
     * Get absolute path of package config file
     */
    protected function getPackageConfigFilePath(string $configName): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $configName . '.php';
    }
}
