<?php /** @noinspection PhpIncludeInspection */

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (file_exists(dirname(__DIR__) . '/config/bootstrap.php')) {
    require dirname(__DIR__) . '/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
}

// Ensure CLI-specific environment variables are set for testing
if (PHP_SAPI === 'cli') {
    $_SERVER['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test'; // Set test environment if not already set
    $_SERVER['SESSION_AUTO_START'] = false; // Disable session auto-start during tests
}
