<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Manages optional stateful token tracking for revocation and one-time-use links.
 *
 * When stateful mode is enabled (APP_TOKEN_STATEFUL=1), this service tracks
 * token hashes in a file-based store. Tokens are stored as SHA-256 hashes
 * (never plaintext) with metadata for expiration, revocation, and usage count.
 *
 * When disabled, validateLink consumes and createLink operates in pure
 * stateless mode (backward compatible).
 */
class TokenStateService
{
    private bool $enabled;
    private string $stateFile;
    private LoggerInterface $logger;
    /** @var array<string, array{nonce_hash: string, exp: int, max_uses: int, used: int, revoked: bool}> */
    private array $store = [];

    public function __construct(
        #[Autowire('%env(bool:APP_TOKEN_STATEFUL)%')]
        bool $enabled,
        #[Autowire('%kernel.project_dir%/var/data/tokens.json')]
        string $stateFile,
        LoggerInterface $logger,
    ) {
        $this->enabled = $enabled;
        $this->stateFile = $stateFile;
        $this->logger = $logger;
    }

    /**
     * Registers a token in the state store when stateful mode is enabled.
     *
     * @param string $token The raw token string (will be hashed before storage)
     * @param int $expiration Unix timestamp when the token expires
     * @param int $maxUses Maximum number of submissions allowed (default 1 for one-time-use)
     */
    public function registerToken(string $token, int $expiration, int $maxUses = 1): void
    {
        if (!$this->enabled) {
            return;
        }

        $hash = $this->hashToken($token);
        $this->loadStore();

        $this->store[$hash] = [
            'nonce_hash' => $hash,
            'exp' => $expiration,
            'max_uses' => $maxUses,
            'used' => 0,
            'revoked' => false,
        ];

        $this->saveStore();
    }

    /**
     * Checks if a token is valid (not revoked, not expired, not exhausted).
     * Returns true if the token is valid and within usage limits.
     *
     * @param string $token The raw token string
     */
    public function validateToken(string $token): bool
    {
        if (!$this->enabled) {
            return true; // Stateless mode: trust the token crypto
        }

        $hash = $this->hashToken($token);
        $this->loadStore();

        if (!isset($this->store[$hash])) {
            // Token not tracked — in stateful mode, this means invalid
            return false;
        }

        $entry = $this->store[$hash];

        if ($entry['revoked']) {
            return false;
        }

        if ($entry['exp'] < time()) {
            return false;
        }

        if ($entry['used'] >= $entry['max_uses']) {
            return false;
        }

        return true;
    }

    /**
     * Records a successful message submission against the token.
     * Increments the usage counter.
     *
     * @param string $token The raw token string
     */
    public function consumeToken(string $token): void
    {
        if (!$this->enabled) {
            return;
        }

        $hash = $this->hashToken($token);
        $this->loadStore();

        if (isset($this->store[$hash])) {
            $this->store[$hash]['used']++;
            $this->saveStore();
        }
    }

    /**
     * Revokes a token so it can no longer be used.
     *
     * @param string $token The raw token string
     */
    public function revokeToken(string $token): void
    {
        if (!$this->enabled) {
            return;
        }

        $hash = $this->hashToken($token);
        $this->loadStore();

        if (isset($this->store[$hash])) {
            $this->store[$hash]['revoked'] = true;
            $this->saveStore();
        }
    }

    /**
     * Returns true if stateful mode is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Hashes a token for secure storage (prevents plaintext token leaks
     * if the state file is compromised).
     */
    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Loads the token store from the JSON file.
     */
    private function loadStore(): void
    {
        if (empty($this->store) && file_exists($this->stateFile)) {
            $json = file_get_contents($this->stateFile);
            $this->store = json_decode($json, true) ?? [];
        }
    }

    /**
     * Saves the token store to the JSON file.
     */
    private function saveStore(): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $json = json_encode($this->store, JSON_PRETTY_PRINT);
        if ($json === false) {
            $this->logger->error('Failed to encode token state store');
            return;
        }

        if (file_put_contents($this->stateFile, $json) === false) {
            $this->logger->error('Failed to write token state store');
        }
    }

    /**
     * Cleans up expired tokens from the store.
     * Should be called periodically (e.g., via cron job).
     */
    public function cleanupExpired(): int
    {
        if (!$this->enabled) {
            return 0;
        }

        $this->loadStore();
        $now = time();
        $count = 0;

        foreach ($this->store as $hash => $entry) {
            if ($entry['exp'] < $now) {
                unset($this->store[$hash]);
                $count++;
            }
        }

        if ($count > 0) {
            $this->saveStore();
        }

        return $count;
    }
}
