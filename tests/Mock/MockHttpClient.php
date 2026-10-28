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
    // Valid-looking PGP public key block with test@example.com UID so that
    // PgpKeyService::keyHasEmailUid() matches the recipient email used in UI tests.
    private const FAKE_KEY = "-----BEGIN PGP PUBLIC KEY BLOCK-----

mDMEat+qiRYJKwYBBAHaRw8BAQdA74GzTLwVRIazGuOSdQPjUzIcgD5zRJdS6Eud
1MXM+ri0HFRlc3QgVXNlciA8dGVzdEBleGFtcGxlLmNvbT6IkAQTFgoAOBYhBGZz
4CN4D3B+y25YNDAIkbjhLypNBQJq36qJAhsDBQsJCAcCBhUKCQgLAgQWAgMBAh4B
AheAAAoJEDAIkbjhLypN4dEBAPGomFX+DkJAM6KRh3bG5LJjbsY8bZDsLEnKGjch
d3H+AQC3SqrzC8LUsOzfKO3Fz1Gyh8PkQ3GqLPVrb2LsZzSjB7g4BGrfqokSCisG
AQQBl1UBBQEBB0Df3pd2XKBPmx6y78pFMwujpoKj2J1cOyMuLxcoJdexaAMBCAeI
eAQYFgoAIBYhBGZz4CN4D3B+y25YNDAIkbjhLypNBQJq36qJAhsMAAoJEDAIkbjh
LypNC2oBAJv4RxXE93y/2K4rzIQLZ3rSVX9HjId03G1mi98oEo/KAQCsxm78zGd1
T6mLtspVkvTS1PJ5UTzWYF/9Xw3xWq1yDw==
=IAHS
-----END PGP PUBLIC KEY BLOCK-----";

    private SymfonyMockHttpClient $inner;

    public function __construct()
    {
        // Use a callable that returns a new MockResponse for every request.
        // This provides unlimited responses — Symfony's MockHttpClient only
        // exhausts responses when given a fixed array. When tests make more
        // requests than the number of responses (e.g., multiple test methods
        // sharing a kernel), a callable avoids "No more response left" errors.
        $this->inner = new SymfonyMockHttpClient(function (string $method, string $url, array $options): MockResponse {
            return new MockResponse(self::FAKE_KEY, ['http_code' => 200]);
        });
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
