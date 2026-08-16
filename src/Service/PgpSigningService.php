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
     * @param string $signedMessage The combined -----BEGIN PGP SIGNED MESSAGE----- block.
     * @param string $publicKey     The armored public key to verify against.
     *
     * @return bool True if the signature is valid, false otherwise.
     * @throws AppException If an unexpected error occurs during verification.
     */
    public function verifySignature(string $signedMessage, string $publicKey): bool
    {
        // Create an isolated temporary keyring for this verification call.
        $tmpHome = rtrim(sys_get_temp_dir(), '/\\') . '/gpg_' . bin2hex(random_bytes(8));
        mkdir($tmpHome, 0700, true);

        try {
            putenv("GNUPGHOME={$tmpHome}");
            $gpg = new gnupg();
            $gpg->seterrormode(gnupg::ERROR_EXCEPTION);

            $keyInfo = $gpg->import($publicKey);
            if (empty($keyInfo) || !isset($keyInfo['fingerprint'])) {
                throw new AppException('Invalid public key format');
            }

            // For combined cleartext-signed messages, pass the full signed block
            // as the first argument and false as the second (no separate plaintext).
            $info = $gpg->verify($signedMessage, false);
            if (!is_array($info) || empty($info)) {
                throw new AppException('Verification error');
            }

            foreach ($info as $sig) {
                if (isset($sig['summary']) && ($sig['summary'] & GNUPG_SIGSUM_RED) === 0) {
                    return true;
                }
            }

            return false;
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
