<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap for wise-agent package tests
 *
 * Uses composer autoload (PSR-4) for all class loading.
 * Manual requires are no longer needed — autoload handles
 * all classes under the wise\agent namespace.
 */

$autoloadPaths = [
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__) . '/../../vendor/autoload.php',
    dirname(__DIR__) . '/../../../vendor/autoload.php',
];

foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

$appRoot = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'app';
spl_autoload_register(static function (string $class) use ($appRoot): void {
    if (str_starts_with($class, 'app\\')) {
        $file = $appRoot . DIRECTORY_SEPARATOR
            . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 4)) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});
