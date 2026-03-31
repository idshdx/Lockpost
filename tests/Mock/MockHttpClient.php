<?php

namespace App\Tests\Mock;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Stub HTTP client for tests — returns a fake PGP public key block so
 * PgpKeyService never makes real network requests to key servers.
 */
class MockHttpClient implements HttpClientInterface
{
    // Minimal valid-looking PGP public key block (content is fake but structurally correct)
    private const FAKE_KEY = "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\nmQENBFakeKeyBQCBfakedata==\n=fake\n-----END PGP PUBLIC KEY BLOCK-----\n";

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return new MockResponse(200, self::FAKE_KEY);
    }

    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new \LogicException('MockHttpClient::stream() is not implemented.');
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}
