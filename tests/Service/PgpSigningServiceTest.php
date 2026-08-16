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

        // Invalid/malformed signatures should return false (not throw).
        $isVerified = $this->pgpSigningService->verifySignature($signedBlock, $publicKey);
        $this->assertFalse($isVerified, 'Malformed signature data must return false');
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
     * Regression test: A validly signed message must verify as true.
     * This confirms the fix does not break legitimate sign→verify workflows.
     */
    public function testValidSignatureReturnsTrue(): void
    {
        $message = 'A message that should verify correctly';
        $signedBlock = $this->pgpSigningService->signMessage($message);
        $publicKey = file_get_contents($this->testPgpDir . '/public.key');

        $result = $this->pgpSigningService->verifySignature($signedBlock, $publicKey);
        $this->assertTrue($result);
    }

    /**
     * Regression test: A tampered signed message (content modified after signing)
     * must return false — the signature no longer matches the message.
     */
    public function testTamperedSignatureReturnsFalse(): void
    {
        $message = 'Original message content';
        $signedBlock = $this->pgpSigningService->signMessage($message);

        // Tamper with the message content inside the signed block.
        $tamperedBlock = str_replace('Original message content', 'Tampered malicious content', $signedBlock);
        $publicKey = file_get_contents($this->testPgpDir . '/public.key');

        $result = $this->pgpSigningService->verifySignature($tamperedBlock, $publicKey);
        $this->assertFalse($result, 'Tampered signature must return false');
    }

    /**
     * Regression test: A signature from a different key (not the imported key)
     * must return false — fingerprint mismatch is detected.
     */
    public function testSignatureFromDifferentKeyReturnsFalse(): void
    {
        // Generate a second PGP key pair in the test keyring.
        $differentKeyGpgHome = $this->testPgpDir . '/different-key-home';
        mkdir($differentKeyGpgHome, 0700, true);

        // Generate a different key
        $batchFile = $differentKeyGpgHome . '/batch.conf';
        file_put_contents($batchFile, "Key-Type: RSA\nKey-Length: 2048\nName-Real: Different Signer\nName-Email: different@test.example\nExpire-Date: 0\n%no-protection\n%commit\n");

        exec(
            'gpg --homedir ' . escapeshellarg($differentKeyGpgHome)
            . ' --batch --import-ownertrust 2>&1',
            $importOutput,
            $importRet
        );

        exec(
            'gpg --homedir ' . escapeshellarg($differentKeyGpgHome)
            . ' --batch --generate-key ' . escapeshellarg($batchFile) . ' 2>&1',
            $genOutput,
            $genRet
        );

        // Export the different key's public key
        $pubKeyFile = $this->testPgpDir . '/different-public.key';
        exec(
            'gpg --homedir ' . escapeshellarg($differentKeyGpgHome)
            . ' --armor --export different@test.example > ' . escapeshellarg($pubKeyFile),
            $exportOutput,
            $exportRet
        );
        $this->assertFileExists($pubKeyFile, 'Different key public key should be exported');

        // Sign a message with the different key
        $message = 'Message signed with a different key';
        $messageFile = $this->testPgpDir . '/message-to-sign.txt';
        file_put_contents($messageFile, $message);
        $signedWithDifferentKey = null;
        exec(
            'gpg --homedir ' . escapeshellarg($differentKeyGpgHome)
            . ' --batch --yes --local-user different@test.example --clearsign'
            . ' -o - < ' . escapeshellarg($messageFile) . ' 2>/dev/null',
            $signOutput,
            $signRet
        );
        $signedWithDifferentKey = implode("\n", $signOutput);
        $this->assertNotEmpty($signedWithDifferentKey, 'Should produce a signed block');

        // The original public key is what we pass to verifySignature.
        // The signature was made by a different key, so verification must fail.
        $originalPublicKey = file_get_contents($this->testPgpDir . '/public.key');
        $result = $this->pgpSigningService->verifySignature($signedWithDifferentKey, $originalPublicKey);

        $this->assertFalse($result, 'Signature from a different key must return false');
    }

    /**
     * Regression test: Malformed signed message input (no PGP structure)
     * should not cause an unhandled exception and must return false.
     */
    public function testMalformedSignedMessageReturnsFalse(): void
    {
        $publicKey = file_get_contents($this->testPgpDir . '/public.key');

        $malformedInputs = [
            'This is not a PGP signed message at all',
            '',
            '-----BEGIN PGP SIGNED MESSAGE-----',
            'Random text without any PGP structure',
        ];

        foreach ($malformedInputs as $input) {
            $result = $this->pgpSigningService->verifySignature($input, $publicKey);
            $this->assertFalse($result, "Malformed input must return false: " . substr($input, 0, 30));
        }
    }

    /**
     * Regression test: Invalid public key input should throw AppException.
     */
    public function testInvalidPublicKeyInputThrowsAppException(): void
    {
        $signedBlock = $this->pgpSigningService->signMessage('test message');

        $invalidKeys = [
            '-----BEGIN PGP PUBLIC KEY BLOCK-----\nInvalid Key\n-----END PGP PUBLIC KEY BLOCK-----',
            'not a key at all',
            '',
            '-----BEGIN PGP PUBLIC KEY BLOCK-----\nMissing end marker',
        ];

        foreach ($invalidKeys as $key) {
            $this->expectException(AppException::class);
            $this->expectExceptionMessage('Invalid public key format');
            try {
                $this->pgpSigningService->verifySignature($signedBlock, $key);
            } catch (AppException $e) {
                $this->assertStringContainsString('Invalid public key format', $e->getMessage());
                throw $e;
            }
        }
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
