<?php

namespace App\Tests\Service;

use App\Exception\AppException;
use App\Service\PgpSigningService;
use PHPUnit\Framework\TestCase;

class PgpSigningServiceTest extends TestCase
{
    private PgpSigningService $pgpSigningService;
    private string $testPrivateKeyPath;
    private string $testPassphrase;

    protected function setUp(): void
    {
        $this->testPrivateKeyPath = __DIR__ . '/../../config/pgp/private.key';
        $this->testPassphrase = 'your-secure-passphrase';
        $this->pgpSigningService = new PgpSigningService(
            $this->testPrivateKeyPath,
            $this->testPassphrase
        );
    }

    public function testSignMessage(): void
    {
        $message = 'Test message to be signed';
        $signedMessage = $this->pgpSigningService->signMessage($message);

        $this->assertNotEmpty($signedMessage);
        $this->assertStringContainsString('-----BEGIN PGP SIGNATURE-----', $signedMessage);
        $this->assertStringContainsString('-----END PGP SIGNATURE-----', $signedMessage);
        // For detached signatures, we verify the format and presence of signature blocks
        $this->assertStringContainsString('-----BEGIN PGP SIGNATURE-----', $signedMessage);
        $this->assertStringContainsString('-----END PGP SIGNATURE-----', $signedMessage);
        $this->assertNotEmpty($signedMessage);
    }

    public function testSignEmptyMessage(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Cannot sign empty message');
        $this->pgpSigningService->signMessage('');
    }

    public function testInvalidPrivateKeyPath(): void
    {
        $this->expectException(AppException::class);
        new PgpSigningService('/invalid/path/to/key', $this->testPassphrase);
    }

    public function testInvalidPassphrase(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Failed to initialize GnuPG');
        new PgpSigningService($this->testPrivateKeyPath, 'wrong-passphrase');
    }

    public function testInvalidKeyPermissions(): void
    {
        // Create a temporary key file with incorrect permissions
        $tempKeyPath = sys_get_temp_dir() . '/test_key.asc';
        file_put_contents($tempKeyPath, file_get_contents($this->testPrivateKeyPath));
        chmod($tempKeyPath, 0644);

        try {
            $this->expectException(AppException::class);
            $this->expectExceptionMessage('Private key file has incorrect permissions');
            new PgpSigningService($tempKeyPath, $this->testPassphrase);
        } finally {
            unlink($tempKeyPath);
        }
    }

    public function testResourceCleanup(): void
    {
        $message = 'Test cleanup message';
        $this->pgpSigningService->signMessage($message);
        
        // Trigger destructor
        unset($this->pgpSigningService);
        
        // Verify we can create a new instance without resource conflicts
        $newService = new PgpSigningService($this->testPrivateKeyPath, $this->testPassphrase);
        $signedMessage = $newService->signMessage($message);
        $this->assertNotEmpty($signedMessage);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->pgpSigningService)) {
            unset($this->pgpSigningService);
        }
    }
}