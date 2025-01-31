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

    /**
     * Constructor for the PgpSigningService class.
     *
     * @param ErrorHandler $errorHandler The error handler for managing exceptions.
     * @param string $privateKeyPath The file path to the private key.
     * @param string $privateKeyPassphrase The passphrase for the private key.
     * @param string $gnupgHome The directory path for GnuPG home, default is '/var/www/app/config/pgp/key-config'.
     * @param string $publicKeyPath The file path to the public key, default is '/var/www/app/config/pgp/public.key'.
     * @param LoggerInterface|null $logger The logger for logging messages, default is a NullLogger.
     * @throws AppException
     */
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

    /**
     * Prepares the GnuPG home directory by resolving the path to the realpath
     * and ensuring the directory exists and has the correct permissions.
     *
     * @param string $gnupgHome The path to the GnuPG home directory
     *
     * @return string The realpath of the GnuPG home directory
     *
     * @throws AppException If the GnuPG home path is not valid or does not exist
     */
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

    /**
     * Initializes the GnuPG home directory by creating it if it does not exist and
     * setting the correct permissions.
     * Also sets the GnuPG home environment variable
     * and creates a new GnuPG instance.
     *
     * If the GnuPG home directory already exists, verifies the permissions and if they
     * are not correct, throws an AppException.
     *
     * @throws AppException If the GnuPG home path exists but is not a directory
     *                      or if the GnuPG home directory could not be created,
     *                      or if the permissions could not be verified
     */
    private function initializeGnupg(): void
    {
        try {
            if (!file_exists($this->gnupgHome)) {

                $this->logger->info('Creating GnuPG home directory', ['path' => $this->gnupgHome]);

                if (!mkdir($concurrentDirectory = $this->gnupgHome, self::DIR_PERMISSIONS, true) && !is_dir($concurrentDirectory)) {
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

    /**
     * Configures the GnuPG instance for the service.
     *
     * Configures the GnuPG instance to throw exceptions on errors, set the
     * armor output to be enabled, and set the signature mode to be detached.
     */
    private function configureGnupg(): void
    {
        $this->gnupg->seterrormode(GNUPG_ERROR_EXCEPTION);
        $this->gnupg->setarmor(1);
        $this->gnupg->setsignmode(GNUPG_SIG_MODE_DETACH);
    }

    /**
     * Imports the private key and verifies it can be used for signing.
     *
     * The method imports the private key using the GnuPG extension and
     * verifies it can be used for signing by checking the subkey capabilities.
     * If the key is not imported or verified successfully, an exception
     * is thrown.
     */
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

    /**
     * Verifies that a file or directory has the expected permissions and is owned by the current user.
     *
     * @param string $path The path to the file or directory to check.
     * @param int $expectedPermissions The expected permissions of the file or directory, in octal format.
     *
     * @throws AppException If the permissions do not match the expected value, or if the current user does not own the file or directory.
     */
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

    /**
     * Signs a given message using the server's private key and returns the detached signature.
     *
     * @param string $message The message to sign.
     *
     * @return string The detached signature of the message.
     *
     * @throws AppException If the message is empty or if signing fails.
     */
    public function signMessage(string $message): string
    {
        if ($message === '') {
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

    /**
     * Retrieves the server's public key as a string.
     *
     * @return string|null The public key data, or null if an error occurs.
     *
     * @throws AppException If the public key file is missing or unreadable, or if reading the file fails.
     */
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

    /**
     * Verifies a given signature using the server's public key.
     *
     * The verification process will import the server's public key and then
     * verify the given signature against the message using the imported key.
     *
     * @param string $message The message to verify.
     * @param string $signature The detached signature to verify against the message.
     *
     * @return bool True if the signature is valid, false otherwise.
     *
     * @throws AppException If the server's public key is missing or unreadable, or if verification fails.
     */
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

    /**
     * Clears the GnuPG keyring and resets the GNUPGHOME environment variable.
     *
     * This method is called in the destructor to ensure that keys are not left in memory.
     */
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
