<?php

declare(strict_types=1);

/**
 * Test bootstrap for eva_ai.
 *
 * Loads the app's own classes via the composer autoloader and - if a
 * Nextcloud installation is available - registers an autoloader for the
 * OCP interfaces so contract tests can run against the real API surface.
 *
 * Set the environment variable NEXTCLOUD_ROOT to point at a Nextcloud
 * checkout (e.g. /var/www/nextcloud). If it cannot be found, the
 * TaskProcessing contract tests are skipped (they need the real OCP
 * interface definitions).
 */

use Composer\Autoload\ClassLoader;

/** @var ClassLoader $loader */
$loader = require __DIR__ . '/../vendor/autoload.php';

// OCP interfaces from a local Nextcloud installation (optional).
$roots = [
    getenv('NEXTCLOUD_ROOT') ?: '',
    '/var/www/nextcloud',
];
$ocpRoot = null;
foreach ($roots as $root) {
    if ($root !== '' && is_dir($root . '/lib/public')) {
        $ocpRoot = $root;
        break;
    }
}

if ($ocpRoot !== null) {
    $loader->addPsr4('OCP\\', $ocpRoot . '/lib/public/');
    // OC\ internal classes (e.g. OC\Hooks\Emitter which OCP interfaces extend).
    if (is_dir($ocpRoot . '/lib/private')) {
        $loader->addPsr4('OC\\', $ocpRoot . '/lib/private/');
    }
    // Psr\Log interfaces are required by the provider constructors; Nextcloud
    // ships them under 3rdparty/psr/log.
    if (is_dir($ocpRoot . '/3rdparty/psr/log/src')) {
        $loader->addPsr4('Psr\\Log\\', $ocpRoot . '/3rdparty/psr/log/src/');
    }
    define('EVA_AI_OCP_AVAILABLE', true);
} else {
    define('EVA_AI_OCP_AVAILABLE', false);
}
