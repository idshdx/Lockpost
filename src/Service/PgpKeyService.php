<?php

namespace App\Service;

use App\Exception\AppException;
use Exception;

class PgpKeyService
{
    private const KEY_SERVERS = [
        'https://keys.openpgp.org',
        'https://keyserver.ubuntu.com',
        'https://pgp.mit.edu'
    ];

    /**
     * Check if a public key exists for a given email address on the configured
     * public key servers.
     *
     * @param string $email The email address to check for a public key.
     *
     * @return bool True if a matching public key was found, false otherwise.
     */
    public function verifyPublicKeyExists(string $email): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        foreach (self::KEY_SERVERS as $server) {
            try {
                $response = file_get_contents("$server/pks/lookup?op=get&search=$email");
                if ($response !== false && str_contains($response, 'BEGIN PGP PUBLIC KEY BLOCK')) {
                    return true;
                }
            } catch (Exception $e) {
                continue;
            }
        }

        return false;
    }

    /**
     * Retrieve the PGP public key for a given email address from configured key servers.
     *
     * This method queries multiple public key servers to find a PGP public key associated
     * with the specified email address.
     * It returns the first valid public key block found.
     *
     * @param string $email The email address to search for the PGP public key.
     *
     * @return string The PGP public key block if found.
     *
     * @throws AppException If no public key could be retrieved for the provided email address.
     */
    public function getPublicKeyByEmail(string $email): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AppException('Invalid email address format');
        }

        foreach (self::KEY_SERVERS as $server) {
            try {
                $response = file_get_contents("$server/pks/lookup?op=get&search=$email");
                if ($response !== false && str_contains($response, 'BEGIN PGP PUBLIC KEY BLOCK')) {
                    if (preg_match('/-+BEGIN PGP PUBLIC KEY BLOCK-+.*?-+END PGP PUBLIC KEY BLOCK-+/s', $response, $matches)) {
                        return trim($matches[0]);
                    }
                }
            } catch (Exception $e) {
                continue;
            }
        }

        throw new AppException('No public key found for the provided email address');
    }
}
