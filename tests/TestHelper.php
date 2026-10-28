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

        // Write gpg.conf with loopback pinentry so GPG can read the passphrase
        // from the gnupg PHP extension call (addsignkey) instead of trying to
        // launch a GUI/CLI pinentry prompt that blocks in non-interactive tests.
        file_put_contents($keyConfigDir . '/gpg.conf', implode("\n", [
            'use-agent',
            'pinentry-mode loopback',
            'no-emit-version',
            'no-comments',
            'export-options export-minimal',
        ]) . "\n");
        chmod($keyConfigDir . '/gpg.conf', 0600);

        // Write gpg-agent.conf with allow-loopback-pinentry so the GPG agent
        // accepts passphrases from the gnupg PHP extension in non-interactive
        // test contexts. Without this, GPG 2.x's agent fails with "no passphrase
        // set" because it tries to launch a pinentry prompt that cannot work
        // in a headless/container environment.
        file_put_contents($keyConfigDir . '/gpg-agent.conf', "allow-loopback-pinentry\n");
        chmod($keyConfigDir . '/gpg-agent.conf', 0600);

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
