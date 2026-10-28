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

        $this->withLock(true, function () use ($hash, $expiration, $maxUses): void {
            $this->loadStore();

            $this->store[$hash] = [
                'nonce_hash' => $hash,
                'exp' => $expiration,
                'max_uses' => $maxUses,
                'used' => 0,
                'revoked' => false,
            ];

            $this->saveStore();
        });
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

        return $this->withLock(false, function () use ($hash): bool {
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
        });
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

        $this->withLock(true, function () use ($hash): void {
            $this->loadStore();

            if (isset($this->store[$hash])) {
                $this->store[$hash]['used']++;
                $this->saveStore();
            }
        });
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

        $this->withLock(true, function () use ($hash): void {
            $this->loadStore();

            if (isset($this->store[$hash])) {
                $this->store[$hash]['revoked'] = true;
                $this->saveStore();
            }
        });
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
     * Runs $fn while holding a lock on the state file.
     *
     * The store is a plain JSON file shared by every PHP-FPM worker, so a
     * read-modify-write cycle (e.g. consumeToken incrementing the counter)
     * can interleave with another worker and silently lose the other
     * worker's update. Serialising those cycles under an advisory flock
     * prevents that lost-update race.
     *
     * Writes take an exclusive lock, reads a shared one. The lock lives in a
     * sidecar file because saveStore() replaces the store file by rename,
     * which would swap out the inode a lock held on the store itself.
     *
     * Locking is best-effort: if the lock file cannot be opened (read-only
     * filesystem, exotic shared storage) the operation still runs rather than
     * failing message submission, and a warning is logged.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $fn
     *
     * @return TReturn
     */
    private function withLock(bool $write, callable $fn): mixed
    {
        $dir = dirname($this->stateFile);

        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            $this->logger->warning('Could not create token state directory; proceeding without lock');
            return $fn();
        }

        $handle = @fopen($dir . '/.tokens.lock', 'c+');

        if ($handle === false) {
            $this->logger->warning('Could not open token state lock file; proceeding without lock');
            return $fn();
        }

        $locked = @flock($handle, $write ? LOCK_EX : LOCK_SH);

        if (!$locked) {
            $this->logger->warning('Could not acquire token state lock; proceeding without lock');
        }

        try {
            return $fn();
        } finally {
            if ($locked) {
                @flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    /**
     * Loads the token store from the JSON file.
     *
     * Always re-reads (rather than caching) so each locked operation sees
     * writes made by other workers. Corrupt JSON is logged and treated as an
     * empty store instead of failing every token operation silently.
     */
    private function loadStore(): void
    {
        if (!file_exists($this->stateFile)) {
            return;
        }

        $json = file_get_contents($this->stateFile);

        if ($json === false) {
            $this->logger->error('Could not read token state store; treating as empty');
            $this->store = [];
            return;
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            $this->logger->error('Token state store contains invalid JSON; treating as empty', [
                'error' => json_last_error_msg(),
            ]);
            $this->store = [];
            return;
        }

        $this->store = $decoded;
    }

    /**
     * Saves the token store to the JSON file atomically.
     *
     * Writes to a temp file in the same directory and renames it into place,
     * so a concurrent reader never sees a truncated/half-written store, and
     * enforces 0600 permissions because the file reveals operational state
     * (token hashes, usage, revocations).
     */
    private function saveStore(): void
    {
        $dir = dirname($this->stateFile);

        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            $this->logger->error('Could not create token state directory');
            return;
        }

        $json = json_encode($this->store, JSON_PRETTY_PRINT);
        if ($json === false) {
            $this->logger->error('Failed to encode token state store');
            return;
        }

        $tmp = @tempnam($dir, 'tokens.');

        if ($tmp === false) {
            $this->logger->error('Failed to create temp file for token state store');
            return;
        }

        // tempnam() silently falls back to the system temp dir when $dir is
        // not writable; a rename across filesystems is not atomic, so bail out.
        if (realpath(dirname($tmp)) !== realpath($dir)) {
            $this->logger->error('Token state directory is not writable; store not saved');
            @unlink($tmp);
            return;
        }

        if (file_put_contents($tmp, $json) === false) {
            $this->logger->error('Failed to write token state store');
            @unlink($tmp);
            return;
        }

        @chmod($tmp, 0600);

        if (!@rename($tmp, $this->stateFile)) {
            $this->logger->error('Failed to move token state store into place');
            @unlink($tmp);
            return;
        }

        @chmod($this->stateFile, 0600);
    }

    /**
     * Cleans up expired tokens from the store.
     * Should be called periodically (e.g., via the app:tokens:cleanup command).
     */
    public function cleanupExpired(): int
    {
        if (!$this->enabled) {
            return 0;
        }

        return $this->withLock(true, function (): int {
            $this->loadStore();
            $now = time();
            $count = 0;

            foreach ($this->store as $hash => $entry) {
                if (!is_array($entry) || ($entry['exp'] ?? 0) < $now) {
                    unset($this->store[$hash]);
                    $count++;
                }
            }

            if ($count > 0) {
                $this->saveStore();
            }

            return $count;
        });
    }
}
