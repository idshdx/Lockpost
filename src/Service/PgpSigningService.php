<?php

namespace App\Service;

use App\Exception\AppException;
use Exception;
use gnupg;

class PgpSigningService
{
    private readonly string $privateKeyPath;
    private readonly string $passphrase;
    private readonly string $keyConfigPath;
    private readonly string $publicKeyPath;
    private readonly string $appEnv;

    /**
     * Cached, pre-initialized GnuPG instance for signing operations.
     * Imported and sign-key set once in the constructor to avoid
     * re-importing the private key on every sign() call.
     */
    private readonly gnupg $gpgSigner;

    /**
     * @param string $privateKeyPath Path to the server's PGP private key file.
     * @param string $passphrase     Passphrase for the private key (empty string for no-protection keys).
     * @param string $keyConfigPath  Path to the GnuPG GNUPGHOME directory for the signing keyring.
     * @param string $publicKeyPath  Path to the server's PGP public key file.
     * @param string $appEnv         The application environment (dev, prod, test).
     *
     * @throws AppException If the private key cannot be read, imported, or if the passphrase is wrong.
     *                      Also throws if APP_ENV is prod/test and passphrase is empty.
     */
    public function __construct(
        string $privateKeyPath,
        string $passphrase,
        string $keyConfigPath,
        string $publicKeyPath,
        string $appEnv
    ) {
        // In production, a passphrase-protected signing key is mandatory.
        // A no-protection key on a production server means the private key
        // on disk is unencrypted — an unacceptable risk if the disk is compromised.
        if (in_array($appEnv, ['prod', 'test'], true) && $passphrase === '') {
            throw new AppException('PGP private key passphrase is required in non-dev environments');
        }

        $this->privateKeyPath = $privateKeyPath;
        $this->passphrase = $passphrase;
        $this->keyConfigPath = $keyConfigPath;
        $this->publicKeyPath = $publicKeyPath;
        $this->appEnv = $appEnv;

        // Runtime permission checks for key material.
        // Docker enforces permissions at build time; these checks catch
        // host-level bind-mount mistakes, bad umasks, or manual overrides.
        $this->validateKeyFilePermissions();

        // Set GNUPGHOME once for this process worker — signing always uses the
        // server keyring. verifySignature() overrides this with a temp dir per call.
        putenv("GNUPGHOME={$this->keyConfigPath}");

        try {
            $this->gpgSigner = $this->buildSigningGpg();
        } catch (Exception $e) {
            $message = $e instanceof AppException ? $e->getMessage() : 'Initialization error';
            throw new AppException($message, 0, $e);
        }
    }

    /**
     * Build and return an initialized gnupg instance with the signing key loaded.
     * Called once from the constructor and cached in $gpgSigner.
     *
     * @throws AppException
     */
    private function buildSigningGpg(): gnupg
    {
        $gpg = new gnupg();
        $gpg->seterrormode(gnupg::ERROR_EXCEPTION);

        $privateKeyData = file_get_contents($this->privateKeyPath);
        if ($privateKeyData === false) {
            throw new AppException('Private key not found');
        }

        $privateKeyInfo = $gpg->import($privateKeyData);
        if (empty($privateKeyInfo) || !isset($privateKeyInfo['fingerprint'])) {
            throw new AppException('Private key mismatch');
        }

        try {
            $gpg->addsignkey($privateKeyInfo['fingerprint'], $this->passphrase);
        } catch (Exception $e) {
            throw new AppException('Invalid passphrase', 0, $e);
        }

        return $gpg;
    }

    /**
     * Signs a message using the server's private key.
     *
     * Returns a combined cleartext-signed PGP block
     * (-----BEGIN PGP SIGNED MESSAGE----- … -----END PGP SIGNATURE-----).
     *
     * @param string $message The plaintext message to sign.
     * @return string The combined PGP cleartext-signed block.
     * @throws AppException If signing fails.
     */
    public function signMessage(string $message): string
    {
        try {
            putenv("GNUPGHOME={$this->keyConfigPath}");
            $signature = $this->gpgSigner->sign($message);
            if ($signature === false) {
                throw new AppException('Invalid signature error');
            }
            return $signature;
        } catch (AppException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new AppException('Unexpected error during signing', 0, $e);
        }
    }

    /**
     * Verifies a combined PGP cleartext-signed message against a public key.
     *
     * Each call creates an isolated temporary GnuPG home directory so that
     * untrusted user-supplied public keys never pollute the server's signing
     * keyring, and there is no process-global putenv() race between workers.
     *
     * Verification is performed via the gpg binary with --status-fd 1 for
     * machine-parseable output, which is more reliable than the PHP gnupg
     * extension's summary field for trust-level decisions.
     *
     * Verification rules:
     * - The public key must import successfully.
     * - The key is assigned ultimate ownertrust in the temp home so GPG
     *   actually validates the signature rather than reporting it as untrusted.
     * - The status output must contain GOODSIG and VALIDSIG lines,
     *   confirming a cryptographically valid signature.
     * - No BADSIG, EXPKEYSIG, REVKEYSIG, or ERRSIG lines may be present.
     * - The VALIDSIG line's primary key fingerprint must match the
     *   imported key's primary fingerprint.
     *
     * @param string $signedMessage The combined -----BEGIN PGP SIGNED MESSAGE----- block.
     * @param string $publicKey     The armored public key to verify against.
     *
     * @return bool True if the signature is valid and from the expected key.
     * @throws AppException If an unexpected error occurs during verification.
     */
    public function verifySignature(string $signedMessage, string $publicKey): bool
    {
        // Create an isolated temporary keyring for this verification call.
        $tmpHome = rtrim(sys_get_temp_dir(), '/\\\\') . '/gpg_' . bin2hex(random_bytes(8));
        if (!mkdir($tmpHome, 0700, true) && !is_dir($tmpHome)) {
            throw new AppException('Failed to create temporary GPG home directory');
        }

        try {
            putenv("GNUPGHOME={$tmpHome}");

            // Write the signed message to a temporary file for gpg --verify.
            $msgFile = $tmpHome . '/signed-message.asc';
            $bytesWritten = file_put_contents($msgFile, $signedMessage);
            if ($bytesWritten === false) {
                throw new AppException('Failed to write signed message for verification');
            }

            // Write the public key to a file for gpg --import.
            $keyFile = $tmpHome . '/pubkey.asc';
            $bytesWritten = file_put_contents($keyFile, $publicKey);
            if ($bytesWritten === false) {
                throw new AppException('Failed to write public key for verification');
            }

            // Import the public key to get its primary fingerprint.
            $gpg = new gnupg();
            $gpg->seterrormode(gnupg::ERROR_EXCEPTION);

            $keyInfo = $gpg->import($publicKey);
            if (empty($keyInfo) || !isset($keyInfo['fingerprint'])) {
                throw new AppException('Invalid public key format');
            }

            $primaryFingerprint = $keyInfo['fingerprint'];

            // Assign ultimate ownertrust so GPG validates the signature.
            $this->setOwnertrust($tmpHome, $primaryFingerprint, $gpg);

            // Run gpg --verify with --status-fd 1 for machine-parseable output.
            $cmd = 'gpg --homedir ' . escapeshellarg($tmpHome)
                . ' --status-fd 1 --verify ' . escapeshellarg($msgFile) . ' 2>&1';
            exec($cmd, $statusLines, $returnVar);

            return $this->parseVerifyStatus($statusLines, $returnVar, $primaryFingerprint);
        } catch (AppException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new AppException('Unexpected error during signature verification', 0, $e);
        } finally {
            // Restore GNUPGHOME to the signing keyring and clean up the temp dir.
            putenv("GNUPGHOME={$this->keyConfigPath}");
            $this->removeTempDir($tmpHome);
        }
    }

    /**
     * Assign ultimate ownertrust to a key so GPG validates signatures against it.
     *
     * @param string $gpgHome     Path to the temporary GnuPG home.
     * @param string $fingerprint The primary key fingerprint to trust.
     * @param gnupg|null $gpg     Optional gnupg instance (unused, kept for API compat).
     */
    private function setOwnertrust(string $gpgHome, string $fingerprint, ?gnupg $gpg = null): void
    {
        $trustFile = $gpgHome . '/.ownertrust';
        $content = $fingerprint . ':6:' . "\n";
        file_put_contents($trustFile, $content);

        // Use gpg CLI to import ownertrust (the PHP extension does not expose this).
        exec(
            'gpg --homedir ' . escapeshellarg($gpgHome) . ' --import-ownertrust 2>&1',
            $output,
            $returnVar
        );

        // Remove the trust file — it has been imported into trustdb.gpg.
        @unlink($trustFile);
    }

    /**
     * Parse gpg --status-fd 1 output to determine signature validity.
     *
     * A signature is considered valid only when:
     * - GOODSIG and VALIDSIG lines are present.
     * - No BADSIG, EXPKEYSIG (expired key), REVKEYSIG (revoked key),
     *   EXP_BADSIG, or ERRSIG lines are present.
     * - The VALIDSIG line's primary key fingerprint matches the expected fingerprint.
     *
     * @param array $statusLines  Lines of output from gpg --status-fd 1 --verify.
     * @param int   $returnVar    Exit code from gpg (may be non-zero even for warnings).
     * @param string $expectedFp  The primary key fingerprint the key should match.
     * @return bool
     */
    private function parseVerifyStatus(array $statusLines, int $returnVar, string $expectedFp): bool
    {
        $hasGoodSig = false;
        $hasValidSig = false;
        $validSigPrimaryFp = '';

        foreach ($statusLines as $line) {
            $line = trim($line);

            // BADSIG: Bad signature
            if (str_starts_with($line, '[GNUPG:] BADSIG')) {
                return false;
            }

            // EXP_BADSIG: Expired signature (bad)
            if (str_starts_with($line, '[GNUPG:] EXP_BADSIG')) {
                return false;
            }

            // ERRSIG: Signature error
            if (str_starts_with($line, '[GNUPG:] ERRSIG')) {
                return false;
            }

            // REVKEYSIG: Key used for signing is revoked
            if (str_starts_with($line, '[GNUPG:] REVKEYSIG')) {
                return false;
            }

            // EXPKEYSIG: Key has expired at the time of verification
            if (str_starts_with($line, '[GNUPG:] EXPKEYSIG')) {
                return false;
            }

            // GOODSIG: A good signature was found
            if (str_starts_with($line, '[GNUPG:] GOODSIG')) {
                $hasGoodSig = true;
            }

            // VALIDSIG: The signature is cryptographically valid.
            // Format: VALIDSIG <fp> <creation-date> <expiry> <hash-class> ...
            // The last field is the primary key fingerprint.
            if (str_starts_with($line, '[GNUPG:] VALIDSIG')) {
                $hasValidSig = true;
                $parts = explode(' ', $line);
                // VALIDSIG <signing_fp> <timestamp> <expiry> <hash_class> <pubkey_algo>
                // <hash_algo> <status> <primary_fp>
                // The primary key fingerprint is the last space-separated field.
                if (count($parts) >= 9) {
                    $validSigPrimaryFp = end($parts);
                }
            }
        }

        // Both GOODSIG and VALIDSIG must be present.
        if (!$hasGoodSig || !$hasValidSig) {
            return false;
        }

        // The VALIDSIG primary key fingerprint must match the imported key.
        if ($validSigPrimaryFp === '' || !hash_equals($validSigPrimaryFp, $expectedFp)) {
            return false;
        }

        return true;
    }

    /**
     * Returns the server's public key as a string.
     *
     * @return string The PGP public key in armored format.
     * @throws AppException If the key file cannot be read.
     */
    public function getServerPublicKey(): string
    {
        try {
            $publicKey = file_get_contents($this->publicKeyPath);
            if ($publicKey === false) {
                throw new AppException('Failed to read server public key file');
            }
            return $publicKey;
        } catch (AppException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new AppException('Failed to read server public key', 0, $e);
        }
    }

    /**
     * Recursively removes a temporary directory and all its contents.
     */
    private function removeTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        @rmdir($dir);
    }

    /**
     * Validate runtime permissions for PGP key files and keying directories.
     *
     * Checks that:
     * - private key is not accessible by group or others (read or write)
     *
     * @throws AppException If a critical permission check fails.
     */
    private function validateKeyFilePermissions(): void
    {
        $privateKey = $this->privateKeyPath;

        if (!is_file($privateKey) || !is_readable($privateKey)) {
            throw new AppException('PGP private key file is missing or unreadable');
        }

        $privatePerms = fileperms($privateKey) ?: 0;

        // Private key must be accessible only by its owner.
        // 0o077 covers group/others read/write/execute bits.
        if (($privatePerms & 0o077) !== 0) {
            throw new AppException('PGP private key must not be accessible by group or others');
        }
    }
}
