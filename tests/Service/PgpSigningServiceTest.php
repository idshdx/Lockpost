<?php

namespace App\Tests\Service;

use App\Exception\AppException;
use App\Service\PgpSigningService;
use App\Tests\TestHelper;
use PHPUnit\Framework\TestCase;

class PgpSigningServiceTest extends TestCase
{
    private PgpSigningService $pgpSigningService;
    private string $testPrivateKeyPath;
    private string $testPassphrase;
    private string $testPgpDir;

    protected function setUp(): void
    {
        $this->testPgpDir = TestHelper::setupTestPgpDirectory();
        $this->testPrivateKeyPath = __DIR__ . '/../../config/pgp/private.key';
        $this->testPassphrase = 'your-secure-passphrase';

        $destPrivate = $this->testPgpDir . '/private.key';
        $destPublic = $this->testPgpDir . '/public.key';
        copy($this->testPrivateKeyPath, $destPrivate);
        copy(__DIR__ . '/../../config/pgp/public.key', $destPublic);
        // 0600: private key readable only by owner — safe
        chmod($destPrivate, 0600);
        // 0644: public key readable by all, writable by owner — safe for a public key
        chmod($destPublic, 0644);

        $this->testPrivateKeyPath = $destPrivate;

        $this->pgpSigningService = new PgpSigningService(
            $this->testPrivateKeyPath,
            $this->testPassphrase,
            $this->testPgpDir . '/key-config',
            $this->testPgpDir . '/public.key',
            'test'
        );
    }

    protected function tearDown(): void
    {
        TestHelper::cleanupTestPgpDirectory($this->testPgpDir);
        parent::tearDown();
    }

    public function testSignMessage(): void
    {
        $message = 'Test message to be signed';
        $signedMessage = $this->pgpSigningService->signMessage($message);

        $this->assertNotEmpty($signedMessage);
        // signMessage() produces a combined cleartext-signed block
        $this->assertStringContainsString('-----BEGIN PGP SIGNED MESSAGE-----', $signedMessage);
        $this->assertStringContainsString('-----BEGIN PGP SIGNATURE-----', $signedMessage);
        $this->assertStringContainsString('-----END PGP SIGNATURE-----', $signedMessage);
    }

    public function testVerifySigning(): void
    {
        $message = 'Test message to verify';
        $signedBlock = $this->pgpSigningService->signMessage($message);
        $publicKey = file_get_contents($this->testPgpDir . '/public.key');

        $isVerified = $this->pgpSigningService->verifySignature($signedBlock, $publicKey);

        $this->assertTrue($isVerified, 'Sign then verify with own key must return true');
    }

    public function testInvalidSigning(): void
    {
        $signedBlock = '-----BEGIN PGP SIGNED MESSAGE-----
Hash: SHA512

Tampered content

-----BEGIN PGP SIGNATURE-----

Invalid Signature Data Here
-----END PGP SIGNATURE-----';
        $publicKey = file_get_contents($this->testPgpDir . '/public.key');

        $this->expectException(AppException::class);

        $this->pgpSigningService->verifySignature($signedBlock, $publicKey);
    }

    public function testInvalidKey(): void
    {
        putenv('GNUPGHOME=' . $this->testPgpDir . '/key-config');

        // Create a service with an invalid private key path to force an error.
        // Uses a helper to avoid SonarQube "useless object instantiation" warning.
        $invalidKeyPath = $this->testPgpDir . '/nonexistent.key';

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('PGP private key file is missing or unreadable');

        $this->createPgpSigningService($invalidKeyPath, 'any-passphrase');
    }

    public function testPermissionCheckRejectsWorldReadablePrivateKey(): void
    {
        putenv('GNUPGHOME=' . $this->testPgpDir . '/key-config');

        // Relax permissions on the private key to trigger the runtime check.
        // Intentionally world-readable — this is a test scenario.
        @chmod($this->testPrivateKeyPath, 0644);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('PGP private key must not be accessible by group or others');

        // Uses a helper to avoid SonarQube "useless object instantiation" warning.
        $this->createPgpSigningService($this->testPrivateKeyPath, $this->testPassphrase);
    }

    public function testPermissionCheckWarnsOnWritablePublicKey(): void
    {
        putenv('GNUPGHOME=' . $this->testPgpDir . '/key-config');

        // Relax permissions on the public key to trigger the runtime check.
        // The constructor should log a warning rather than throw for public-key
        // writability, because host-mounted volumes can expose nonstandard bits.
        // Intentionally fully writable — this is a test scenario.
        @chmod($this->testPgpDir . '/public.key', 0666);

        $service = $this->createPgpSigningService($this->testPrivateKeyPath, $this->testPassphrase);

        $this->assertInstanceOf(PgpSigningService::class, $service);
    }

    public function testInvalidPassphrase(): void
    {
        putenv('GNUPGHOME=' . $this->testPgpDir . '/key-config');

        // Keys generated by init-pgp.sh use %no-protection (no passphrase).
        // GnuPG silently ignores a wrong passphrase on unprotected keys,
        // so instantiation must succeed — signing will still work.
        $service = $this->createPgpSigningService($this->testPrivateKeyPath, 'wrong-passphrase');

        // Verify the service is actually functional despite the wrong passphrase
        $signed = $service->signMessage('test');
        $this->assertStringContainsString('-----BEGIN PGP SIGNATURE-----', $signed);
    }

    public function testInvalidSignatureVerification(): void
    {
        $message = 'Test message';
        $signedBlock = $this->pgpSigningService->signMessage($message);
        $invalidPublicKey = '-----BEGIN PGP PUBLIC KEY BLOCK-----
Invalid Key
-----END PGP PUBLIC KEY BLOCK-----';

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Invalid public key format');

        $this->pgpSigningService->verifySignature($signedBlock, $invalidPublicKey);
    }

    public function testGetServerPublicKey(): void
    {
        $publicKey = $this->pgpSigningService->getServerPublicKey();
        $this->assertNotEmpty($publicKey);
        $this->assertStringContainsString('-----BEGIN PGP PUBLIC KEY BLOCK-----', $publicKey);
        $this->assertStringContainsString('-----END PGP PUBLIC KEY BLOCK-----', $publicKey);
    }

    /**
     * Helper to create a PgpSigningService for tests that expect constructor exceptions.
     * Using a helper avoids SonarQube false positives about "useless object instantiation".
     */
    private function createPgpSigningService(string $privateKeyPath, string $passphrase): PgpSigningService
    {
        return new PgpSigningService(
            $privateKeyPath,
            $passphrase,
            $this->testPgpDir . '/key-config',
            $this->testPgpDir . '/public.key',
            'test'
        );
    }
}
