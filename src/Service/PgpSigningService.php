<?php

namespace App\Service;

use App\Exception\AppException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class PgpSigningService
{
    private $gnupg;
    private string $privateKeyPath;
    private string $privateKeyPassphrase;
    private LoggerInterface $logger;
    private string $gnupgHome;
    private const KEY_PERMISSIONS = 0600;
    private const DIR_PERMISSIONS = 0700;

    private string $publicKeyPath;

    public function __construct(
        string $privateKeyPath,
        string $privateKeyPassphrase,
        string $gnupgHome = '/var/www/app/config/pgp/key-config',
        string $publicKeyPath = '/var/www/app/config/pgp/public.key',
        ?LoggerInterface $logger = null
    ) {
        $this->privateKeyPath = $privateKeyPath;
        $this->privateKeyPassphrase = $privateKeyPassphrase;
        $this->gnupgHome = $gnupgHome;
        $this->publicKeyPath = $publicKeyPath;
        $this->logger = $logger ?? new NullLogger();
        $this->initializeGnupg();
    }

    private function initializeGnupg(): void
    {
        try {
            // Ensure GnuPG home directory exists and has correct permissions
            if (!file_exists($this->gnupgHome)) {
                $this->logger->info('Creating GnuPG home directory', ['path' => $this->gnupgHome]);
                if (!mkdir($this->gnupgHome, self::DIR_PERMISSIONS, true)) {
                    $this->logger->error('Failed to create GnuPG home directory', ['path' => $this->gnupgHome]);
                    throw new AppException('Failed to create GnuPG home directory');
                }
            } elseif (!is_dir($this->gnupgHome)) {
                throw new AppException('GnuPG home path exists but is not a directory');
            } else {
                // Verify directory permissions if it already exists
                $dirPerms = fileperms($this->gnupgHome) & 0777;
                if ($dirPerms !== self::DIR_PERMISSIONS) {
                    $this->logger->warning('Fixing GnuPG home directory permissions', [
                        'path' => $this->gnupgHome,
                        'current_perms' => decoct($dirPerms),
                        'expected_perms' => decoct(self::DIR_PERMISSIONS)
                    ]);
                    chmod($this->gnupgHome, self::DIR_PERMISSIONS);
                }
            }

            // Set GNUPGHOME environment variable
            putenv("GNUPGHOME={$this->gnupgHome}");

            // Initialize GnuPG with the home directory
            $this->gnupg = new \gnupg();
            $this->configureGnupg();
            $this->importAndVerifyKey();

        } catch (\Exception $e) {
            throw new AppException('Failed to initialize GnuPG: ' . $e->getMessage());
        }
    }

    private function configureGnupg(): void
    {
        $this->gnupg->seterrormode(\GNUPG_ERROR_EXCEPTION);
        $this->gnupg->setarmor(1);
        $this->gnupg->setsignmode(\GNUPG_SIG_MODE_DETACH);
    }

    private function importAndVerifyKey(): void
    {
        // Verify file permissions
        $filePerms = fileperms($this->privateKeyPath) & 0777;
        if ($filePerms !== self::KEY_PERMISSIONS) {
            throw new AppException('Private key file has incorrect permissions. Expected 0600');
        }

        $privateKeyData = file_get_contents($this->privateKeyPath);
        if ($privateKeyData === false) {
            throw new AppException('Failed to read private key file');
        }

        $importResult = $this->gnupg->import($privateKeyData);
        if (!$importResult) {
            throw new AppException('Failed to import private key');
        }

        $keyInfo = $this->gnupg->keyinfo($importResult['fingerprint']);
        if (empty($keyInfo)) {
            throw new AppException('Failed to get key information');
        }

        // Verify key validity
        if (!isset($keyInfo[0]['subkeys'][0]['can_sign']) || !$keyInfo[0]['subkeys'][0]['can_sign']) {
            throw new AppException('The private key cannot be used for signing');
        }

        $this->gnupg->addsignkey(
            $keyInfo[0]['subkeys'][0]['fingerprint'],
            $this->privateKeyPassphrase
        );
    }

    public function signMessage(string $message): string
    {
        if (empty(trim($message))) {
            throw new AppException('Cannot sign empty message');
        }

        try {
            $this->logger->info('Attempting to sign message');
            $signature = $this->gnupg->sign($message);
            if ($signature === false) {
                $this->logger->error('Failed to sign message');
                throw new AppException('Failed to sign message');
            }

            $this->logger->info('Message signed successfully');
            return $signature;
        } catch (\Exception $e) {
            throw new AppException('Error signing message: ' . $e->getMessage());
        } finally {
            $this->cleanup();
        }
    }

    public function getServerPublicKey(): ?string
    {
        if (!is_readable($this->publicKeyPath)) {
            $this->logger->error('Public key file is missing or unreadable.', [
                'path' => $this->publicKeyPath
            ]);
            return null;
        }

        $keyData = file_get_contents($this->publicKeyPath);
        if ($keyData === false) {
            throw new AppException('Failed to read the public key file.');
        }

        return $keyData;
    }

    public function verifySignature(string $message, string $signature): bool
    {
        try {
            $publicKeyData = $this->getPublicKey();

            if (!$publicKeyData) {
                throw new AppException('No public key available.');
            }

            // Import Public Key
            $importResult = $this->gnupg->import($publicKeyData);
            if (!$importResult) {
                throw new AppException('Invalid public key format.');
            }

            // Verify Signature
            $verificationResult = $this->gnupg->verify($message, $signature);

            return !empty($verificationResult)
                && isset($verificationResult[0]['summary'])
                && ($verificationResult[0]['summary'] === 0 || $verificationResult[0]['summary'] & \GNUPG_SIGSUM_VALID);
        } catch (\Exception $e) {
            $this->logger->error('Signature verification failed', [
                'error' => $e->getMessage(),
                'message_length' => strlen($message),
                'signature_length' => strlen($signature),
            ]);
            return false;
        }
    }


    private function cleanup(): void
    {
        if ($this->gnupg) {
            $this->gnupg->clearsignkeys();
            $this->gnupg->cleardecryptkeys();
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
