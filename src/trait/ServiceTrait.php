<?php
declare(strict_types=1);

namespace wise\agent\trait;

use think\facade\Config;

/**
 * WiseAdmin 包 Service 提供者共享 trait
 *
 * 为 wise-* 包提供配置合并和发布工具。
 */
trait ServiceTrait
{
    /**
     * 将包默认配置合并到全局配置
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
     * 将配置文件发布到应用程序配置目录
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
     * 递归合并数组（覆盖优先于基础）
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
     * 加载包默认配置文件
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
     * 获取包配置文件的绝对路径
     */
    protected function getPackageConfigFilePath(string $configName): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $configName . '.php';
    }
}
