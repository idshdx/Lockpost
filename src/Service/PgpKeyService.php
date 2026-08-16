<?php

namespace App\Service;

use App\Exception\AppException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class PgpKeyService
{
    /**
     * Key servers are queried in order. keys.openpgp.org is first because it
     * performs email-verified key publishing, making it the most trustworthy
     * source for a recipient's verified public key.
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
     * server that returns a valid PGP public key block.
     */
    public function verifyPublicKeyExists(string $email): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return $this->findFirstValidBody($email) !== null;
    }

    /**
     * Retrieve the PGP public key for a given email address.
     *
     * Queries all key servers concurrently and short-circuits on the first
     * server that returns a valid PGP public key block, cancelling the
     * remaining in-flight requests to minimise latency.
     *
     * @throws AppException If no public key could be retrieved.
     */
    public function getPublicKeyByEmail(string $email): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AppException('Invalid email address format');
        }

        $body = $this->findFirstValidBody($email);

        if ($body === null) {
            throw new AppException('No public key found for the provided email address');
        }

        if (preg_match('/-+BEGIN PGP PUBLIC KEY BLOCK-+.*?-+END PGP PUBLIC KEY BLOCK-+/s', $body, $matches)) {
            return trim($matches[0]);
        }

        throw new AppException('No public key found for the provided email address');
    }

    /**
     * Fire all key-server requests concurrently and short-circuit on the
     * first response that contains a valid PGP public key block.
     *
     * All requests are dispatched simultaneously. Each response is checked
     * via getStatusCode() and getContent(false) — the false parameter
     * suppresses HTTP error exceptions on 4xx/5xx. The first response with
     * a 2xx status AND a key block wins; its body is returned immediately.
     * All remaining responses are cancelled in a finally block so that
     * MockResponse::__destruct never throws ClientException for unconsumed
     * responses in tests.
     *
     * @return string|null The first valid response body, or null if none found.
     */
    private function findFirstValidBody(string $email): ?string
    {
        $responses = [];
        foreach (self::KEY_SERVERS as $server) {
            $responses[] = $this->httpClient->request('GET', "$server/pks/lookup", [
                'query'         => ['op' => 'get', 'search' => $email],
                'timeout'       => self::TIMEOUT,
                'max_redirects' => 3,
                'http_errors'   => false,
            ]);
        }

        try {
            foreach ($responses as $response) {
                try {
                    $statusCode = $response->getStatusCode();
                    $body = $response->getContent(false);

                    if ($statusCode >= 200 && $statusCode < 300
                        && str_contains($body, 'BEGIN PGP PUBLIC KEY BLOCK')) {
                        return $body;
                    }
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
}
