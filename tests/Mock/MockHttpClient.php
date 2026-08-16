<?php

namespace App\Tests\Mock;

use Symfony\Component\HttpClient\MockHttpClient as SymfonyMockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Stub HTTP client for tests — delegates to Symfony's MockHttpClient so
 * that PgpKeyService::getPublicKeyByEmail() can use stream() and response
 * cancellation in tests without making real network requests.
 *
 * Wraps each response body in a MockResponse with a 200 status code so
 * that Symfony's MockHttpClient accepts them properly.
 */
class MockHttpClient implements HttpClientInterface
{
    // Minimal valid-looking PGP public key block (content is fake but structurally correct)
    private const FAKE_KEY = "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\nmQENBFakeKeyBQCBfakedata==\n=fake\n-----END PGP PUBLIC KEY BLOCK-----\n";

    private SymfonyMockHttpClient $inner;

    public function __construct()
    {
        // Wrap strings in MockResponse objects — Symfony's MockHttpClient
        // requires ResponseInterface instances or a callable, not bare strings.
        $this->inner = new SymfonyMockHttpClient([
            new MockResponse(self::FAKE_KEY, ['http_code' => 200]),
            new MockResponse(self::FAKE_KEY, ['http_code' => 200]),
            new MockResponse(self::FAKE_KEY, ['http_code' => 200]),
        ]);
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->inner->request($method, $url, $options);
    }

    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);
        return $clone;
    }
}
