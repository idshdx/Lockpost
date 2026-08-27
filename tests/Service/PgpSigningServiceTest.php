<?php

namespace App\Tests\Service;

use App\Exception\AppException;
use App\Service\PgpSigningService;
use App\Tests\TestHelper;
use PHPUnit\Framework\TestCase;

class PgpSigningServiceTest extends TestCase
{
    private string $testPrivateKeyPath;
    private string $testPassphrase;
    private string $testPgpDir;
    private string $keyConfigPath;
    private string $publicKeyPath;

    protected function setUp(): void
    {
        $this->testPgpDir = TestHelper::setupTestPgpDirectory();
        $this->testPassphrase = 'your-secure-passphrase';

        $destPrivate = $this->testPgpDir . '/private.key';
        $destPublic = $this->testPgpDir . '/public.key';
        copy(__DIR__ . '/../../config/pgp/private.key', $destPrivate);
        copy(__DIR__ . '/../../config/pgp/public.key', $destPublic);
        // 0600: private key readable only by owner — safe
        chmod($destPrivate, 0600);
        // 0644: public key readable by all, writable by owner — safe for a public key
        chmod($destPublic, 0644);

        $this->keyConfigPath = $this->testPgpDir . '/key-config';
        $this->testPrivateKeyPath = $destPrivate;
        $this->publicKeyPath = $destPublic;
    }

    protected function tearDown(): void
    {
        TestHelper::cleanupTestPgpDirectory($this->testPgpDir);
        parent::tearDown();
    }

    public function testSignMessage(): void
    {
        $service = $this->createService();

        $message = 'Test message to be signed';
        $signedMessage = $service->signMessage($message);

        $this->assertNotEmpty($signedMessage);
        // signMessage() produces a combined cleartext-signed block
        $this->assertStringContainsString('-----BEGIN PGP SIGNED MESSAGE-----', $signedMessage);
        $this->assertStringContainsString('-----BEGIN PGP SIGNATURE-----', $signedMessage);
        $this->assertStringContainsString('-----END PGP SIGNATURE-----', $signedMessage);
    }

    public function testVerifySigning(): void
    {
        $service = $this->createService();

        $message = 'Test message to verify';
        $signedBlock = $service->signMessage($message);
        $publicKey = file_get_contents($this->publicKeyPath);

        $isVerified = $service->verifySignature($signedBlock, $publicKey);
        $this->assertTrue($isVerified, 'Sign then verify with own key must return true');
    }

    public function testInvalidSigning(): void
    {
        $service = $this->createService();

        $signedBlock = '-----BEGIN PGP SIGNED MESSAGE-----
Hash: SHA512

Tampered content

-----BEGIN PGP SIGNATURE-----

Invalid Signature Data Here
-----END PGP SIGNATURE-----';
        $publicKey = file_get_contents($this->publicKeyPath);

        // Invalid/malformed signatures should return false (not throw).
        $isVerified = $service->verifySignature($signedBlock, $publicKey);
        $this->assertFalse($isVerified, 'Malformed signature data must return false');
    }

    public function testInvalidKey(): void
    {
        // Create a service with an invalid private key path to force an error.
        $invalidKeyPath = $this->testPgpDir . '/nonexistent.key';

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('PGP private key file is missing or unreadable');

        $this->createService($invalidKeyPath);
    }

    public function testPermissionCheckRejectsWorldReadablePrivateKey(): void
    {
        // Relax permissions on the private key to trigger the runtime check.
        // Intentionally world-readable — this is a test scenario.
        @chmod($this->testPrivateKeyPath, 0644);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('PGP private key must not be accessible by group or others');

        $this->createService();
    }

    public function testInvalidPassphrase(): void
    {
        // The server PGP key is generated with --with-passphrase, making it
        // passphrase-protected. The PgpSigningService constructor accepts the
        // passphrase and calls addsignkey() (which does not validate it
        // immediately). However, sign() fails at runtime when gpg-agent
        // cannot unlock the key with the wrong passphrase.
        //
        // This test verifies that:
        // 1. Instantiation succeeds with a wrong passphrase (addsignkey doesn't validate)
        // 2. Signing throws AppException because the key cannot be unlocked
        $service = $this->createService($this->testPrivateKeyPath, 'wrong-passphrase');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Unexpected error during signing');

        $service->signMessage('test');
    }

    public function testInvalidSignatureVerification(): void
    {
        $service = $this->createService();

        $message = 'Test message';
        $signedBlock = $service->signMessage($message);
        $invalidPublicKey = '-----BEGIN PGP PUBLIC KEY BLOCK-----
Invalid Key
-----END PGP PUBLIC KEY BLOCK-----';

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Invalid public key format');

        $service->verifySignature($signedBlock, $invalidPublicKey);
    }

    public function testGetServerPublicKey(): void
    {
        $service = $this->createService();

        $publicKey = $service->getServerPublicKey();
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
        $service = $this->createService();

        $message = 'A message that should verify correctly';
        $signedBlock = $service->signMessage($message);
        $publicKey = file_get_contents($this->publicKeyPath);

        $result = $service->verifySignature($signedBlock, $publicKey);
        $this->assertTrue($result);
    }

    /**
     * Regression test: A tampered signed message (content modified after signing)
     * must return false — the signature no longer matches the message.
     */
    public function testTamperedSignatureReturnsFalse(): void
    {
        $service = $this->createService();

        $message = 'Original message content';
        $signedBlock = $service->signMessage($message);

        // Tamper with the message content inside the signed block.
        $tamperedBlock = str_replace('Original message content', 'Tampered malicious content', $signedBlock);
        $publicKey = file_get_contents($this->publicKeyPath);

        $result = $service->verifySignature($tamperedBlock, $publicKey);
        $this->assertFalse($result, 'Tampered signature must return false');
    }

    /**
     * Regression test: A signature from a different key (not the imported key)
     * must return false — fingerprint mismatch is detected.
     */
    public function testSignatureFromDifferentKeyReturnsFalse(): void
    {
        $service = $this->createService();

        // Generate a second PGP key pair in a separate temp directory.
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
        $originalPublicKey = file_get_contents($this->publicKeyPath);
        $result = $service->verifySignature($signedWithDifferentKey, $originalPublicKey);

        $this->assertFalse($result, 'Signature from a different key must return false');
    }

    /**
     * Regression test: Malformed signed message input (no PGP structure)
     * should not cause an unhandled exception and must return false.
     */
    public function testMalformedSignedMessageReturnsFalse(): void
    {
        $service = $this->createService();
        $publicKey = file_get_contents($this->publicKeyPath);

        $malformedInputs = [
            'This is not a PGP signed message at all',
            '',
            '-----BEGIN PGP SIGNED MESSAGE-----',
            'Random text without any PGP structure',
        ];

        foreach ($malformedInputs as $input) {
            $result = $service->verifySignature($input, $publicKey);
            $this->assertFalse($result, 'Malformed input must return false: ' . substr($input, 0, 30));
        }
    }

    /**
     * Regression test: Invalid public key input should throw AppException.
     */
    public function testInvalidPublicKeyInputThrowsAppException(): void
    {
        $service = $this->createService();
        $signedBlock = $service->signMessage('test message');

        $invalidKeys = [
            '-----BEGIN PGP PUBLIC KEY BLOCK-----
Invalid Key
-----END PGP PUBLIC KEY BLOCK-----',
            'not a key at all',
            '',
            '-----BEGIN PGP PUBLIC KEY BLOCK-----
Missing end marker',
        ];

        foreach ($invalidKeys as $key) {
            $this->expectException(AppException::class);
            $this->expectExceptionMessage('Invalid public key format');
            try {
                $service->verifySignature($signedBlock, $key);
            } catch (AppException $e) {
                $this->assertStringContainsString('Invalid public key format', $e->getMessage());
                throw $e;
            }
        }
    }

    /**
     * Regression test: Verification does not affect the signing keyring.
     * After a verifySignature() call (which uses a temp keyring), signing
     * must still work with the original server keyring.
     */
    public function testSigningStillWorksAfterVerification(): void
    {
        $service = $this->createService();

        // First, do a verification (which creates a temp keyring)
        $signedBlock = $service->signMessage('test message');
        $publicKey = file_get_contents($this->publicKeyPath);
        $service->verifySignature($signedBlock, $publicKey);

        // Now sign again — must still work with the original keyring
        $signedAgain = $service->signMessage('second message after verify');
        $this->assertStringContainsString('-----BEGIN PGP SIGNATURE-----', $signedAgain);

        // And verify the second signature too
        $this->assertTrue($service->verifySignature($signedAgain, $publicKey));
    }

    /**
     * Regression test: Back-to-back signing and verification operations
     * must both work correctly, proving keyring isolation.
     */
    public function testBackToBackSigningAndVerification(): void
    {
        $service = $this->createService();
        $publicKey = file_get_contents($this->publicKeyPath);

        // Sign, verify, sign, verify in sequence
        $signed1 = $service->signMessage('first message');
        $this->assertTrue($service->verifySignature($signed1, $publicKey));

        $signed2 = $service->signMessage('second message');
        $this->assertTrue($service->verifySignature($signed2, $publicKey));

        $signed3 = $service->signMessage('third message');
        $this->assertTrue($service->verifySignature($signed3, $publicKey));
    }

    /**
     * Helper to create a PgpSigningService with the default test parameters.
     *
     * @param string|null $privateKeyPath Override the private key path (for error tests).
     * @param string|null $passphrase     Override the passphrase (for passphrase tests).
     */
    private function createService(
        ?string $privateKeyPath = null,
        ?string $passphrase = null
    ): PgpSigningService {
        return new PgpSigningService(
            $privateKeyPath ?? $this->testPrivateKeyPath,
            $passphrase ?? $this->testPassphrase,
            $this->keyConfigPath,
            $this->publicKeyPath,
            'test'
        );
    }
}
