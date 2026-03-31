<?php

namespace App\Tests\Service;

use App\Exception\AppException;
use App\Service\PgpKeyService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PgpKeyServiceTest extends TestCase
{
    private const VALID_KEY_BODY = "-----BEGIN PGP PUBLIC KEY BLOCK-----\nfakekey\n-----END PGP PUBLIC KEY BLOCK-----";

    private function makeService(array $responses): PgpKeyService
    {
        // Pass null as baseUri to prevent MockHttpClient from resolving
        // absolute URLs against a default base, which would strip the host.
        return new PgpKeyService(new MockHttpClient($responses, null));
    }

    private function notFound(): MockResponse
    {
        // 404 with no key block — must be skipped without throwing
        return new MockResponse('Not found', ['http_code' => 404]);
    }

    private function validKey(): MockResponse
    {
        return new MockResponse(self::VALID_KEY_BODY, ['http_code' => 200]);
    }

    // --- verifyPublicKeyExists ---

    public function testVerifyPublicKeyExistsReturnsTrueWhenKeyFound(): void
    {
        $service = $this->makeService([
            $this->validKey(),
            $this->notFound(),
            $this->notFound(),
        ]);

        $this->assertTrue($service->verifyPublicKeyExists('user@example.com'));
    }

    public function testVerifyPublicKeyExistsReturnsFalseWhenNoKeyFound(): void
    {
        $service = $this->makeService([
            $this->notFound(),
            $this->notFound(),
            $this->notFound(),
        ]);

        $this->assertFalse($service->verifyPublicKeyExists('nobody@example.com'));
    }

    public function testVerifyPublicKeyExistsReturnsFalseForInvalidEmail(): void
    {
        $service = $this->makeService([]);

        $this->assertFalse($service->verifyPublicKeyExists('not-an-email'));
    }

    public function testVerifyPublicKeyExistsReturnsTrueWhenSecondResponseHasKey(): void
    {
        // MockHttpClient with stream() processes responses in FIFO order.
        // First response is a 404, second has the key — service must still find it.
        $service = $this->makeService([
            $this->notFound(),
            $this->validKey(),
            $this->notFound(),
        ]);

        $this->assertTrue($service->verifyPublicKeyExists('user@example.com'));
    }

    public function testVerifyPublicKeyExistsReturnsFalseWhenAllServersFail(): void
    {
        $service = $this->makeService([
            new MockResponse('error', ['http_code' => 500]),
            new MockResponse('error', ['http_code' => 500]),
            new MockResponse('error', ['http_code' => 500]),
        ]);

        $this->assertFalse($service->verifyPublicKeyExists('user@example.com'));
    }

    // --- getPublicKeyByEmail ---

    public function testGetPublicKeyByEmailReturnsKeyBlock(): void
    {
        $service = $this->makeService([
            $this->validKey(),
            $this->notFound(),
            $this->notFound(),
        ]);

        $key = $service->getPublicKeyByEmail('user@example.com');

        $this->assertStringContainsString('BEGIN PGP PUBLIC KEY BLOCK', $key);
        $this->assertStringContainsString('END PGP PUBLIC KEY BLOCK', $key);
    }

    public function testGetPublicKeyByEmailThrowsForInvalidEmail(): void
    {
        $service = $this->makeService([]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Invalid email address format');

        $service->getPublicKeyByEmail('not-an-email');
    }

    public function testGetPublicKeyByEmailThrowsWhenNoServerHasKey(): void
    {
        $service = $this->makeService([
            $this->notFound(),
            $this->notFound(),
            $this->notFound(),
        ]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('No public key found for the provided email address');

        $service->getPublicKeyByEmail('nobody@example.com');
    }

    public function testGetPublicKeyByEmailFallsBackToLaterResponse(): void
    {
        $service = $this->makeService([
            $this->notFound(),
            $this->validKey(),
            $this->notFound(),
        ]);

        $key = $service->getPublicKeyByEmail('user@example.com');

        $this->assertStringContainsString('BEGIN PGP PUBLIC KEY BLOCK', $key);
    }

    public function testGetPublicKeyByEmailThrowsWhenAllServersError(): void
    {
        $service = $this->makeService([
            new MockResponse('error', ['http_code' => 500]),
            new MockResponse('error', ['http_code' => 500]),
            new MockResponse('error', ['http_code' => 500]),
        ]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('No public key found for the provided email address');

        $service->getPublicKeyByEmail('user@example.com');
    }
}
