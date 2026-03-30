<?php

/**
 * AgentReady — Single-file autoloader.
 *
 * Usage:
 *   require_once 'path/to/agentready/autoload.php';
 *
 * That's it. All AgentReady classes are now available.
 */

spl_autoload_register(function (string $class) {
    $prefix = 'AgentReady\\';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
