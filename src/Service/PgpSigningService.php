<?php

namespace App\Service;

use App\Exception\AppException;
use Exception;
use Psr\Log\LoggerInterface;
use App\Exception\ErrorHandler;
use Psr\Log\NullLogger;
use const GNUPG_ERROR_EXCEPTION;
use const GNUPG_SIG_MODE_DETACH;
use const GNUPG_SIGSUM_VALID;
use gnupg;

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
    private ErrorHandler $errorHandler;

    public function __construct(
        ErrorHandler     $errorHandler,
        string           $privateKeyPath,
        string           $privateKeyPassphrase,
        string           $gnupgHome = '/var/www/app/config/pgp/key-config',
        string           $publicKeyPath = '/var/www/app/config/pgp/public.key',
        ?LoggerInterface $logger = null
    )
    {
        $this->errorHandler = $errorHandler;
        $this->privateKeyPath = $privateKeyPath;
        $this->privateKeyPassphrase = $privateKeyPassphrase;
        $this->gnupgHome = $this->prepareGnupgHome($gnupgHome);
        $this->publicKeyPath = $publicKeyPath;
        $this->logger = $logger ?? new NullLogger();
        $this->initializeGnupg();
    }

    private function prepareGnupgHome(string $gnupgHome): string
    {
        try {
            $realPath = realpath($gnupgHome);
            if ($realPath === false) {
                throw new AppException('GnuPG home path is not valid or does not exist.');
            }
            return $realPath;
        } catch (Exception $e) {
            $this->errorHandler->handleServiceException($e, 'Failed to sign message');
        }
    }

    private function initializeGnupg(): void
    {
        try {
            if (!file_exists($this->gnupgHome)) {
                
                $this->logger->info('Creating GnuPG home directory', ['path' => $this->gnupgHome]);
                
                if (!mkdir($this->gnupgHome, self::DIR_PERMISSIONS, true)) {
                    throw new AppException('Failed to create GnuPG home directory');
                }

            } elseif (!is_dir($this->gnupgHome)) {
                throw new AppException('GnuPG home path exists but is not a directory');
            } else {
                $this->verifyPermissions($this->gnupgHome, self::DIR_PERMISSIONS);
            }

            putenv("GNUPGHOME={$this->gnupgHome}");
            $this->gnupg = new gnupg();
            $this->configureGnupg();
            $this->importAndVerifyKey();
        } catch (Exception $e) {
            $this->errorHandler->handleServiceException($e, 'Failed to initialize GnuPG');
        }
    }

    private function configureGnupg(): void
    {
        $this->gnupg->seterrormode(GNUPG_ERROR_EXCEPTION);
        $this->gnupg->setarmor(1);
        $this->gnupg->setsignmode(GNUPG_SIG_MODE_DETACH);
    }

    private function importAndVerifyKey(): void
    {
        try {
            $this->verifyPermissions($this->privateKeyPath, self::KEY_PERMISSIONS);

            $privateKeyData = file_get_contents($this->privateKeyPath);
            if ($privateKeyData === false) {
                throw new AppException('Failed to read private key file');
            }

            $importResult = $this->gnupg->import($privateKeyData);
            if (!$importResult || empty($importResult['fingerprint'])) {
                throw new AppException('Failed to import private key');
            }

            $keyInfo = $this->gnupg->keyinfo($importResult['fingerprint']);
            if (empty($keyInfo)) {
                throw new AppException('Failed to get key information');
            }

            if (!isset($keyInfo[0]['subkeys'][0]['can_sign']) || !$keyInfo[0]['subkeys'][0]['can_sign']) {
                throw new AppException('The private key cannot be used for signing');
            }

            $this->gnupg->addsignkey(
                $keyInfo[0]['subkeys'][0]['fingerprint'],
                $this->privateKeyPassphrase
            );

            unset($privateKeyData);
        } catch (Exception $e) {
            $this->errorHandler->handleServiceException($e, 'Failed to import and verify key');
        }
    }

    private function verifyPermissions(string $path, int $expectedPermissions): void
    {
        $filePerms = fileperms($path) & 0777;
        if ($filePerms !== $expectedPermissions) {
            throw new AppException(sprintf(
                'File or directory "%s" has incorrect permissions. Expected: %o, found: %o',
                $path,
                $expectedPermissions,
                $filePerms
            ));
        }

        if (fileowner($path) !== posix_getuid()) {
            throw new AppException(sprintf(
                'File or directory "%s" is not owned by the current user.',
                $path
            ));
        }
    }

    public function signMessage(string $message): string
    {
        if ($message === null || $message === '') {
            throw new AppException('Cannot sign empty message');
        }

        try {
            $this->logger->info('Attempting to sign message');
            $signature = $this->gnupg->sign($message);

            if ($signature === false) {
                throw new AppException('Failed to sign message');
            }

            $this->logger->info('Message signed successfully');
            return $signature;
        } catch (Exception $e) {
            $this->errorHandler->handleServiceException($e, 'Error signing message');
        }
    }

    public function getServerPublicKey(): ?string
    {
        try {
            if (!is_readable($this->publicKeyPath)) {
                throw new AppException('Public key file is missing or unreadable');
            }

            $keyData = file_get_contents($this->publicKeyPath);
            if ($keyData === false) {
                throw new AppException('Failed to read the public key file');
            }

            return $keyData;
        } catch (Exception $e) {
            $this->errorHandler->handleServiceException($e, 'Failed to retrieve server public key');
        }
    }

    public function verifySignature(string $message, string $signature): bool
    {
        try {
            $publicKeyData = $this->getServerPublicKey();
            if (!$publicKeyData) {
                throw new AppException('No public key available');
            }

            $importResult = $this->gnupg->import($publicKeyData);
            if (!$importResult || empty($importResult['fingerprint'])) {
                throw new AppException('Invalid public key format');
            }

            $verificationResult = $this->gnupg->verify($message, $signature);

            return !empty($verificationResult)
                && isset($verificationResult[0]['summary'])
                && ($verificationResult[0]['summary'] === 0 || $verificationResult[0]['summary'] & GNUPG_SIGSUM_VALID);
        } catch (Exception $e) {
            $this->errorHandler->handleServiceException($e, 'Signature verification failed');
        }
    }

    private function cleanup(): void
    {
        if ($this->gnupg) {
            $this->gnupg->clearsignkeys();
            $this->gnupg->cleardecryptkeys();
        }
        putenv("GNUPGHOME");
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
