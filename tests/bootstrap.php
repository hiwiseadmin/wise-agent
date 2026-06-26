<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap for wise-agent package tests
 *
 * Uses composer autoload (PSR-4) for all class loading.
 * Manual requires are no longer needed — autoload handles
 * all classes under the wise\agent namespace.
 */

// Load composer autoload (adjust path based on your project structure)
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
