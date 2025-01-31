<?php

namespace App\Tests;

use App\Exception\ErrorHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class TestHelper
{
    public static function createErrorHandler(?LoggerInterface $logger = null): ErrorHandler
    {
        return new ErrorHandler($logger ?? new NullLogger());
    }

    public static function setupTestPgpDirectory(): string
    {
        $testPgpDir = sys_get_temp_dir() . '/pgp-test-' . uniqid();
        mkdir($testPgpDir, 0700, true);
        chmod($testPgpDir, 0700);

        // Create key config directory
        $keyConfigDir = $testPgpDir . '/key-config';
        mkdir($keyConfigDir, 0700, true);
        chmod($keyConfigDir, 0700);

        // Create GnuPG home directory structure
        mkdir($keyConfigDir . '/private-keys-v1.d', 0700, true);
        mkdir($keyConfigDir . '/openpgp-revocs.d', 0700, true);
        touch($keyConfigDir . '/pubring.kbx');
        touch($keyConfigDir . '/trustdb.gpg');
        chmod($keyConfigDir . '/pubring.kbx', 0600);
        chmod($keyConfigDir . '/trustdb.gpg', 0600);

        return $testPgpDir;
    }

    public static function cleanupTestPgpDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }

            rmdir($directory);
        }
    }
}
