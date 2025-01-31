<?php

namespace App\Service;

use App\Exception\AppException;
use App\Exception\ErrorHandler;
use Exception;
use gnupg;

class PgpSigningService
{
    private string $privateKeyPath;
    private string $passphrase;
    private string $keyConfigPath;
    private string $publicKeyPath;
    private const GNUPG_SIGSUM_VALID = 0;

/**
 * PgpSigningService constructor.
 *
 * Initializes the PgpSigningService with the necessary paths and settings
 * for GnuPG operations.
 *
 * @param ErrorHandler $errorHandler  The error handler for managing exceptions.
 * @param string       $privateKeyPath Path to the private key file.
 * @param string       $passphrase     The passphrase for the private key.
 * @param string       $keyConfigPath  Path to the GnuPG key configuration directory.
 * @param string       $publicKeyPath  Path to the public key file.
 *
 * @throws AppException If GnuPG initialization fails due to an invalid passphrase.
 */
    public function __construct(
        string $privateKeyPath,
        string $passphrase,
        string $keyConfigPath,
        string $publicKeyPath
    ) {
        $this->privateKeyPath = $privateKeyPath;
        $this->passphrase = $passphrase;
        $this->keyConfigPath = $keyConfigPath;
        $this->publicKeyPath = $publicKeyPath;

        try {
            $this->initializeGnuPG();
        } catch (Exception $e) {
            throw new AppException('Initialization error');
        }
    }

    /**
     * Initialize GnuPG with the provided private key and passphrase.
     *
     * This method will throw an AppException if the passphrase is invalid.
     *
     * @return gnupg The initialized gnupg object.
     *
     * @throws AppException If the passphrase is invalid or if there is an error
     *                      initializing gnupg.
     */
    private function initializeGnuPG(): gnupg
    {
        putenv("GNUPGHOME={$this->keyConfigPath}");
        $gpg = new gnupg();
        $gpg->seterrormode(gnupg::ERROR_EXCEPTION);

        try {
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
                throw new AppException('Invalid passphrase');
            }

            return $gpg;
        } catch (AppException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new AppException('Initialization error');
        }
    }

    /**
     * Signs a given message using the server's private key and returns the
     * generated signature.
     *
     * @param string $message The message to sign.
     * @return string The generated signature.
     * @throws AppException If the signing failed.
     */
    public function signMessage(string $message): string
    {
        try {
            $gpg = $this->initializeGnuPG();
            $signature = $gpg->sign($message);
            if ($signature === false) {
                throw new AppException('Invalid signature error');
            }
            return $signature;
        } catch (Exception $e) {
            throw new AppException('Unexpected error during signing: ' . $e->getMessage());
        }
    }

    /**
     *
     * @param string $message The message to verify the signature for.
     * @param string $signature The signature to verify.
     * @param string $publicKey The public PGP key to use for verification.
     *
     * @return bool True if the signature is valid for the given message and public key, false otherwise.
     *
     * @throws AppException If an unexpected error occurs during verification.
     */
    public function verifySignature(string $message, string $signature, string $publicKey): bool
    {
        try {
            putenv("GNUPGHOME={$this->keyConfigPath}");
            $gpg = new gnupg();
            $gpg->seterrormode(gnupg::ERROR_EXCEPTION);

            $keyInfo = $gpg->import($publicKey);
            if (empty($keyInfo) || !isset($keyInfo['fingerprint'])) {
                throw new AppException('Invalid public key format');
            }

            $info = $gpg->verify($signature, $message);
            if (!is_array($info) || empty($info)) {
                throw new AppException('Verification error');
            }

            foreach ($info as $sig) {
                if (isset($sig['summary']) && $sig['summary'] === self::GNUPG_SIGSUM_VALID) {
                    return true;
                }
            }

            throw new AppException('Verification error');
        } catch (Exception $e) {
            throw new AppException('Unexpected error during signature verification: ' . $e->getMessage());
        }
    }

    /**
     * Returns the server's public key as a string.
     *
     * Reads the public key from the configured file path and returns it as a string.
     * If the file is not readable or does not exist, an AppException is thrown with
     * a descriptive error message.
     *
     * @return string The server's public key as a string.
     * @throws AppException If there is an error reading the public key.
     */
    public function getServerPublicKey(): string
    {
        try {
            $publicKey = file_get_contents($this->publicKeyPath);
            if ($publicKey === false) {
                throw new AppException('Failed to read server public key file');
            }
            return $publicKey;
        } catch (Exception $e) {
            throw new AppException('Failed to read server public key: ' . $e->getMessage());
        }
    }

}
