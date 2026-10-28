<?php

namespace App\Tests\Service;

use App\Service\TokenStateService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class TokenStateServiceTest extends TestCase
{
    private string $dir;
    private string $stateFile;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tokenstate-' . uniqid('', true);
        mkdir($this->dir, 0750, true);
        $this->stateFile = $this->dir . '/tokens.json';
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->dir . '/' . $entry;
            if (is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir($this->dir);
    }

    private function service(bool $enabled = true, ?LoggerInterface $logger = null): TokenStateService
    {
        return new TokenStateService($enabled, $this->stateFile, $logger ?? new NullLogger());
    }

    // --- validateToken basics ---

    public function testRegisteredTokenValidates(): void
    {
        $service = $this->service();
        $service->registerToken('tok-valid', time() + 60);

        $this->assertTrue($service->validateToken('tok-valid'));
    }

    public function testUnregisteredTokenIsInvalidWhenEnabled(): void
    {
        $service = $this->service();

        $this->assertFalse($service->validateToken('tok-unknown'));
    }

    public function testExpiredTokenIsInvalid(): void
    {
        $service = $this->service();
        $service->registerToken('tok-expired', time() - 10);

        $this->assertFalse($service->validateToken('tok-expired'));
    }

    // --- one-time-use / revocation ---

    public function testConsumeExhaustsSingleUseToken(): void
    {
        $service = $this->service();
        $service->registerToken('tok-once', time() + 60, 1);

        $this->assertTrue($service->validateToken('tok-once'), 'before use');

        $service->consumeToken('tok-once');

        $this->assertFalse($service->validateToken('tok-once'), 'after single use');
    }

    public function testTokenWithMultipleUsesStaysValidUntilExhausted(): void
    {
        $service = $this->service();
        $service->registerToken('tok-thrice', time() + 60, 3);

        $service->consumeToken('tok-thrice');
        $service->consumeToken('tok-thrice');
        $this->assertTrue($service->validateToken('tok-thrice'), '2 of 3 used');

        $service->consumeToken('tok-thrice');
        $this->assertFalse($service->validateToken('tok-thrice'), '3 of 3 used');
    }

    public function testRevokeTokenInvalidatesIt(): void
    {
        $service = $this->service();
        $service->registerToken('tok-revoked', time() + 60);

        $service->revokeToken('tok-revoked');

        $this->assertFalse($service->validateToken('tok-revoked'));
    }

    // --- disabled (stateless) mode ---

    public function testDisabledModeValidatesAnyTokenAndWritesNothing(): void
    {
        $service = $this->service(enabled: false);

        $this->assertTrue($service->validateToken('anything'));
        $service->consumeToken('anything');
        $service->revokeToken('anything');
        $service->registerToken('anything', time() + 60);

        $this->assertFileDoesNotExist($this->stateFile, 'stateless mode must not create a store');
    }

    public function testDisabledCleanupReturnsZero(): void
    {
        $this->assertSame(0, $this->service(enabled: false)->cleanupExpired());
    }

    // --- persistence across instances (workers) ---

    public function testSecondInstanceSeesWritesFromFirstInstance(): void
    {
        $first = $this->service();
        $first->registerToken('tok-shared', time() + 60);

        // A different instance (separate PHP-FPM worker) starts with an empty
        // in-memory store and must read the state written by the first one.
        $second = $this->service();

        $this->assertTrue($second->validateToken('tok-shared'));
    }

    public function testInstanceReloadsStoreAfterExternalChange(): void
    {
        $workerA = $this->service();
        $workerA->registerToken('tok-race', time() + 60);
        $this->assertTrue($workerA->validateToken('tok-race'));

        // Another worker revokes the token after A already loaded the store.
        $workerB = $this->service();
        $workerB->revokeToken('tok-race');

        // A must observe the revocation, not its own stale snapshot.
        $this->assertFalse($workerA->validateToken('tok-race'));
    }

    public function testStoreIsPersistedWithRestrictivePermissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permission bits are not meaningful on Windows.');
        }

        $service = $this->service();
        $service->registerToken('tok-perms', time() + 60);

        $this->assertFileExists($this->stateFile);
        $this->assertSame(0600, fileperms($this->stateFile) & 0777);
    }

    public function testStoreNeverContainsPlaintextToken(): void
    {
        $service = $this->service();
        $service->registerToken('tok-plaintext-check', time() + 60);

        $contents = file_get_contents($this->stateFile);
        $this->assertNotFalse($contents);
        $this->assertStringNotContainsString('tok-plaintext-check', $contents);
        $this->assertStringContainsString(hash('sha256', 'tok-plaintext-check'), $contents);
    }

    // --- corrupt store handling ---

    public function testCorruptStoreIsLoggedAndTreatedAsEmpty(): void
    {
        file_put_contents($this->stateFile, '{this is not json');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('invalid JSON'));

        $service = $this->service(logger: $logger);

        $this->assertFalse($service->validateToken('tok-anything'));
    }

    // --- cleanup ---

    public function testCleanupRemovesOnlyExpiredEntries(): void
    {
        $service = $this->service();
        $service->registerToken('tok-alive', time() + 3600);
        $service->registerToken('tok-dead', time() - 10);
        $service->registerToken('tok-dead-too', time() - 5);

        $removed = $service->cleanupExpired();

        $this->assertSame(2, $removed);
        $this->assertTrue($service->validateToken('tok-alive'));
        $this->assertFalse($service->validateToken('tok-dead'));
        $this->assertFalse($service->validateToken('tok-dead-too'));

        // Second run has nothing left to remove.
        $this->assertSame(0, $service->cleanupExpired());
    }
}
