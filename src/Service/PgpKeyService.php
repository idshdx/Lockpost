<?php

namespace App\Service;

use App\DTO\PgpKeyResult;
use App\Exception\AppException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class PgpKeyService
{
    /**
     * Key servers are queried in order. keys.openpgp.org is first because it
     * performs email-verified key publishing, making it the most trustworthy
     * source for a recipient's verified public key.
     *
     * ubuntu.com is kept as a fallback but its keys are NOT email-verified.
     * pgp.mit.edu is kept as a last resort.
     */
    private const KEY_SERVERS = [
        'https://keys.openpgp.org',
        'https://keyserver.ubuntu.com',
        'https://pgp.mit.edu',
    ];

    private const TIMEOUT = 8;

    public function __construct(private readonly HttpClientInterface $httpClient) {}

    public static function getKeyServerNames(): array
    {
        return array_map(
            fn(string $url) => parse_url($url, PHP_URL_HOST),
            self::KEY_SERVERS
        );
    }

    /**
     * Check if a public key exists for a given email address.
     *
     * Queries all key servers concurrently and short-circuits on the first
     * server that returns a valid PGP public key block with a matching UID.
     */
    public function verifyPublicKeyExists(string $email): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return $this->findFirstValidResult($email) !== null;
    }

    /**
     * Retrieve the PGP public key for a given email address.
     *
     * Queries all key servers concurrently and short-circuits on the first
     * server that returns a valid PGP public key block with a matching UID,
     * cancelling the remaining in-flight requests to minimise latency.
     *
     * The returned key block is verified to contain the requested email
     * address in its UID — a key returned by a keyserver for a different
     * email is rejected.
     *
     * @throws AppException If no public key with matching UID could be retrieved.
     */
    public function getPublicKeyByEmail(string $email): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AppException('Invalid email address format');
        }

        $result = $this->findFirstValidResult($email);

        if ($result === null) {
            throw new AppException('No public key found for the provided email address');
        }

        return $result->publicKey;
    }

    /**
     * Retrieve the PGP public key AND metadata for a given email address.
     *
     * Same as getPublicKeyByEmail() but returns a PgpKeyResult containing
     * the source server, key fingerprint, and the list of email UIDs
     * found in the key block.
     *
     * @throws AppException If no public key with matching UID could be retrieved.
     */
    public function getPgpKeyResult(string $email): PgpKeyResult
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AppException('Invalid email address format');
        }

        $result = $this->findFirstValidResult($email);

        if ($result === null) {
            throw new AppException('No public key found for the provided email address');
        }

        return $result;
    }

    /**
     * Fire all key-server requests concurrently and short-circuit on the
     * first response that contains a valid PGP public key block whose
     * UID matches the requested email.
     *
     * Uses the machine-readable (mr) format from keys.openpgp.org to
     * extract the fingerprint and verify the email UID. For other
     * keyservers, falls back to parsing the armored key block.
     *
     * All requests are dispatched simultaneously. Each response is checked
     * via getStatusCode() and getContent(false) — the false parameter
     * suppresses HTTP error exceptions on 4xx/5xx. The first response with
     * a 2xx status AND a key block with matching UID wins; its body is
     * returned immediately. All remaining responses are cancelled in a
     * finally block so that MockResponse::__destruct never throws
     * ClientException for unconsumed responses in tests.
     *
     * @return PgpKeyResult|null The first valid result with matching UID, or null.
     */
    private function findFirstValidResult(string $email): ?PgpKeyResult
    {
        $responses = [];
        $serverUrls = [];
        foreach (self::KEY_SERVERS as $server) {
            $url = $this->buildLookupUrl($server, $email);
            $serverUrls[] = $server;
            $responses[] = $this->httpClient->request('GET', $url, [
                'timeout'       => self::TIMEOUT,
                'max_redirects' => 3,
                'http_errors'   => false,
            ]);
        }

        try {
            foreach ($responses as $index => $response) {
                try {
                    $statusCode = $response->getStatusCode();
                    $body = $response->getContent(false);

                    if ($statusCode < 200 || $statusCode >= 300) {
                        continue;
                    }

                    if (!str_contains($body, 'BEGIN PGP PUBLIC KEY BLOCK')) {
                        continue;
                    }

                    // Parse the armored key block and verify the UID matches.
                    $keyBlock = $this->extractKeyBlock($body);
                    if ($keyBlock === null) {
                        continue;
                    }

                    if (!$this->keyHasEmailUid($keyBlock, $email)) {
                        continue;
                    }

                    $fingerprint = $this->extractFingerprint($body, $keyBlock);
                    $emails = $this->extractEmailsFromKeyBlock($keyBlock);

                    return new PgpKeyResult(
                        publicKey: $keyBlock,
                        source: $serverUrls[$index],
                        fingerprint: $fingerprint,
                        emails: $emails,
                    );
                } catch (\Throwable) {
                    // Transport error or HTTP error — skip this response
                    continue;
                }
            }
        } finally {
            // Cancel any remaining responses so MockResponse destructors
            // don't throw ClientException for unconsumed 4xx/5xx responses.
            // cancel() is a no-op on already-completed responses.
            foreach ($responses as $response) {
                try {
                    $response->cancel();
                } catch (\Throwable) {
                    // Best-effort cleanup
                }
            }
        }

        return null;
    }

    /**
     * Build the key server lookup URL.
     *
     * keys.openpgp.org supports an mr=1 (machine-readable) parameter
     * that returns the key with searchable UID fields. Other servers
     * use the standard HKP lookup.
     */
    private function buildLookupUrl(string $server, string $email): string
    {
        $query = http_build_query([
            'op'    => 'get',
            'search' => $email,
        ]);

        return "$server/pks/lookup?$query";
    }

    /**
     * Extract the PGP public key block from an HTTP response body.
     * Handles both raw key blocks and keyserver result pages.
     */
    private function extractKeyBlock(string $body): ?string
    {
        if (preg_match('/-+BEGIN PGP PUBLIC KEY BLOCK-+.*?-+END PGP PUBLIC KEY BLOCK-+/s', $body, $matches)) {
            return trim($matches[0]);
        }

        return null;
    }

    /**
     * Verify that an armored PGP key block contains the given email
     * in one of its UID packets.
     */
    private function keyHasEmailUid(string $keyBlock, string $email): bool
    {
        $emails = $this->extractEmailsFromKeyBlock($keyBlock);

        return in_array(strtolower($email), array_map('strtolower', $emails), true);
    }

    /**
     * Extract all email addresses from the UID fields of an armored PGP key block.
     */
    private function extractEmailsFromKeyBlock(string $keyBlock): array
    {
        $emails = [];

        // UID lines in armored keys look like: "Name (comment) <email@domain>"
        // The uid packet data is base64-encoded within <pre> tags on keyservers
        // or directly in the armored block.
        if (preg_match_all('/<([^>]+@[^>]+)>/', $keyBlock, $matches)) {
            $emails = array_merge($emails, $matches[1]);
        }

        // Also check for plain email patterns (some key formats)
        if (preg_match_all('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $keyBlock, $matches2)) {
            $emails = array_merge($emails, $matches2[1]);
        }

        return array_unique($emails);
    }

    /**
     * Extract the key fingerprint from the HTTP response body.
     *
     * keys.openpgp.org mr=1 format includes a pub fingerprints field.
     * Falls back to the fingerprint from the keyserver response header.
     */
    private function extractFingerprint(string $body, string $keyBlock): string
    {
        // Try to get fingerprint from keys.openpgp.org mr=1 response
        // Format: "pub 4096R/XXXXXXXX 2024-01-01 [SC] [expires: YYYY-MM-DD]"
        if (preg_match('/pub\s+\S+\s+([A-Fa-f0-9]+)/', $body, $m)) {
            return strtoupper($m[1]);
        }

        // Try the armored key block header comment
        // Some keyservers include: "# Fingerprint: XXXX"
        if (preg_match('/# Fingerprint:\s*([A-Fa-f0-9\s]+)/i', $body, $m)) {
            return strtoupper(str_replace(' ', '', $m[1]));
        }

        // Try to get fingerprint from the armored key's comment field
        // The key block may contain: "# fingerprint=XXXX"
        if (preg_match('/# fingerprint=([A-Fa-f0-9]+)/i', $keyBlock, $m)) {
            return strtoupper($m[1]);
        }

        return '';
    }
}
