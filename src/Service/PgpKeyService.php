<?php

namespace App\Service;

use App\Exception\AppException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PgpKeyService
{
    private const KEY_SERVERS = [
        'https://keys.openpgp.org',
        'https://keyserver.ubuntu.com',
        'https://pgp.mit.edu',
    ];

    public static function getKeyServerNames(): array
    {
        return array_map(
            fn(string $url) => parse_url($url, PHP_URL_HOST),
            self::KEY_SERVERS
        );
    }

    private const TIMEOUT = 8;

    public function __construct(private readonly HttpClientInterface $httpClient) {}

    /**
     * Check if a public key exists for a given email address.
     */
    public function verifyPublicKeyExists(string $email): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Collect all bodies first (fully consuming all responses), then check
        foreach ($this->collectBodies($email) as $body) {
            if (str_contains($body, 'BEGIN PGP PUBLIC KEY BLOCK')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieve the PGP public key for a given email address.
     *
     * @throws AppException If no public key could be retrieved.
     */
    public function getPublicKeyByEmail(string $email): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AppException('Invalid email address format');
        }

        foreach ($this->collectBodies($email) as $body) {
            if (str_contains($body, 'BEGIN PGP PUBLIC KEY BLOCK')) {
                if (preg_match('/-+BEGIN PGP PUBLIC KEY BLOCK-+.*?-+END PGP PUBLIC KEY BLOCK-+/s', $body, $matches)) {
                    return trim($matches[0]);
                }
            }
        }

        throw new AppException('No public key found for the provided email address');
    }

    /**
     * Fire all requests concurrently, wait for all to complete, and return
     * an array of successful (2xx) response bodies.
     *
     * All responses are always fully consumed so that MockResponse::__destruct
     * never throws ClientException in tests when responses are abandoned early.
     *
     * @return string[]
     */
    private function collectBodies(string $email): array
    {
        // Fire all requests simultaneously
        $responses = [];
        foreach (self::KEY_SERVERS as $server) {
            $responses[] = $this->httpClient->request('GET', "$server/pks/lookup", [
                'query'         => ['op' => 'get', 'search' => $email],
                'timeout'       => self::TIMEOUT,
                'max_redirects' => 3,
            ]);
        }

        // Consume ALL responses before returning — this prevents MockResponse::__destruct
        // from throwing ClientException when a 4xx response is garbage-collected unconsumed.
        $bodies = [];
        foreach ($responses as $response) {
            try {
                // getContent(false) suppresses HTTP status exceptions in the real client.
                // MockResponse may still throw ClientException during initialization for 4xx —
                // we catch all Throwable to handle both real and mock clients uniformly.
                $body = $response->getContent(false);
                $statusCode = $response->getStatusCode();

                if ($statusCode >= 200 && $statusCode < 300 && $body !== '') {
                    $bodies[] = $body;
                }
            } catch (\Throwable) {
                // Transport error or HTTP error — skip this server
            }
        }

        return $bodies;
    }
}
