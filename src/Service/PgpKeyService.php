<?php

namespace App\Service;

use App\Exception\AppException;

class PgpKeyService
{
    private const KEY_SERVERS = [
        'https://keys.openpgp.org',
        'https://keyserver.ubuntu.com',
        'https://pgp.mit.edu'
    ];

    public function verifyPublicKey(string $email): bool
    {
        foreach (self::KEY_SERVERS as $server) {
            try {
                $response = file_get_contents("$server/pks/lookup?op=get&search=$email");
                if ($response !== false && str_contains($response, 'BEGIN PGP PUBLIC KEY BLOCK')) {
                    return true;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return false;
    }

    public function getPublicKey(string $email): string
    {
        foreach (self::KEY_SERVERS as $server) {
            try {
                $response = file_get_contents("$server/pks/lookup?op=get&search=$email");
                if ($response !== false && str_contains($response, 'BEGIN PGP PUBLIC KEY BLOCK')) {
                    preg_match('/-----BEGIN PGP PUBLIC KEY BLOCK-----.*?-----END PGP PUBLIC KEY BLOCK-----/s', $response, $matches);
                    if (!empty($matches[0])) {
                        return $matches[0];
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        throw new AppException('Could not retrieve PGP public key');
    }
}