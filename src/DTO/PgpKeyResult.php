<?php

namespace App\DTO;

/**
 * Result of a PGP public key lookup.
 *
 * Contains the raw armored key block plus metadata about where
 * the key was found and which email addresses it claims to serve.
 */
class PgpKeyResult
{
    public function __construct(
        public readonly string $publicKey,
        public readonly string $source,
        public readonly string $fingerprint,
        public readonly array $emails,
    ) {
    }
}
