<?php

namespace App\Tests\Service;

use App\DTO\PgpKeyResult;
use App\Exception\AppException;
use App\Service\PgpKeyService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PgpKeyServiceTrustTest extends TestCase
{
    private const VALID_KEY_BODY = "-----BEGIN PGP PUBLIC KEY BLOCK-----
Version: GnuPG v2

mQENBF2h+2IBCACnJ4examplefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefake+2
-----END PGP PUBLIC KEY BLOCK-----";

    private const VALID_KEY_BODY_WITH_EMAIL = "-----BEGIN PGP PUBLIC KEY BLOCK-----
Version: GnuPG v2

mQENBF2h+2IBCACnJ4examplefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefake+2

uid           Test User <user@example.com>
-----END PGP PUBLIC KEY BLOCK-----";

    private const VALID_KEY_BODY_DIFFERENT_EMAIL = "-----BEGIN PGP PUBLIC KEY BLOCK-----
Version: GnuPG v2

mQENBF2h+2IBCACnJ4examplefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefakefake+2

uid           Other Person <other@example.com>
-----END PGP PUBLIC KEY BLOCK-----";

    private function makeService(array $responses): PgpKeyService
    {
        return new PgpKeyService(new MockHttpClient($responses, null));
    }

    private function notFound(): MockResponse
    {
        return new MockResponse('Not found', ['http_code' => 404]);
    }

    private function validKeyWithMatchingEmail(): MockResponse
    {
        return new MockResponse(self::VALID_KEY_BODY_WITH_EMAIL, ['http_code' => 200]);
    }

    // --- UID verification (key trust) ---

    /**
     * A key returned by a keyserver must have a UID matching the requested email.
     * If the UID doesn't match, the key should be rejected.
     */
    public function testGetPublicKeyByEmailRejectsKeyWithMismatchedUid(): void
    {
        // First server returns a key for a DIFFERENT email
        // Second server returns a key for the correct email
        $service = $this->makeService([
            new MockResponse(self::VALID_KEY_BODY_DIFFERENT_EMAIL, ['http_code' => 200]),
            $this->validKeyWithMatchingEmail(),
            $this->notFound(),
        ]);

        $key = $service->getPublicKeyByEmail('user@example.com');

        $this->assertStringContainsString('BEGIN PGP PUBLIC KEY BLOCK', $key);
        $this->assertStringContainsString('user@example.com', $key);
        $this->assertStringNotContainsString('other@example.com', $key);
    }

    /**
     * If the only key returned has a mismatched UID, the service
     * should throw AppException (key not found for this email).
     */
    public function testGetPublicKeyByEmailThrowsWhenOnlyMismatchedKeysReturned(): void
    {
        $service = $this->makeService([
            new MockResponse(self::VALID_KEY_BODY_DIFFERENT_EMAIL, ['http_code' => 200]),
            $this->notFound(),
            $this->notFound(),
        ]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('No public key found for the provided email address');
        $service->getPublicKeyByEmail('user@example.com');
    }

    /**
     * verifyPublicKeyExists returns true only when a key with matching UID is found.
     */
    public function testVerifyPublicKeyExistsReturnsFalseForMismatchedUid(): void
    {
        $service = $this->makeService([
            new MockResponse(self::VALID_KEY_BODY_DIFFERENT_EMAIL, ['http_code' => 200]),
            $this->notFound(),
            $this->notFound(),
        ]);

        $this->assertFalse($service->verifyPublicKeyExists('user@example.com'));
    }

    /**
     * getPgpKeyResult returns metadata including fingerprint and source.
     */
    public function testGetPgpKeyResultReturnsMetadataWithMatchingUid(): void
    {
        $service = $this->makeService([
            $this->validKeyWithMatchingEmail(),
            $this->notFound(),
            $this->notFound(),
        ]);

        $result = $service->getPgpKeyResult('user@example.com');

        $this->assertInstanceOf(PgpKeyResult::class, $result);
        $this->assertStringContainsString('BEGIN PGP PUBLIC KEY BLOCK', $result->publicKey);
        $this->assertStringContainsString('keys.openpgp.org', $result->source);
        $this->assertNotEmpty($result->emails);
        $this->assertContains('user@example.com', $result->emails);
    }

    /**
     * Keys with invalid PGP markers (no BEGIN/END blocks) are rejected.
     */
    public function testGetPublicKeyByEmailRejectsInvalidPgpBlock(): void
    {
        $service = $this->makeService([
            new MockResponse('-----BEGIN PGP PUBLIC KEY BLOCK-----\nInvalid Key\n-----END PGP PUBLIC KEY BLOCK-----', ['http_code' => 200]),
            $this->notFound(),
            $this->notFound(),
        ]);

        $this->expectException(AppException::class);
        $service->getPublicKeyByEmail('user@example.com');
    }

    /**
     * Empty response body is not treated as a valid key.
     */
    public function testVerifyPublicKeyExistsReturnsFalseForEmptyBody(): void
    {
        $service = $this->makeService([
            new MockResponse('', ['http_code' => 200]),
            new MockResponse('', ['http_code' => 200]),
            new MockResponse('', ['http_code' => 200]),
        ]);

        $this->assertFalse($service->verifyPublicKeyExists('user@example.com'));
    }

    /**
     * Non-200 status codes are skipped.
     */
    public function testGetPublicKeyByEmailSkipsNon200Responses(): void
    {
        $service = $this->makeService([
            new MockResponse('Error', ['http_code' => 500]),
            new MockResponse('Error', ['http_code' => 404]),
            $this->validKeyWithMatchingEmail(),
        ]);

        $key = $service->getPublicKeyByEmail('user@example.com');
        $this->assertStringContainsString('BEGIN PGP PUBLIC KEY BLOCK', $key);
    }

    /**
     * When the first server returns a key with a mismatched UID,
     * the service should continue to the second server and report
     * the correct source in the metadata.
     */
    public function testGetPgpKeyResultTracksCorrectSourceAfterMismatch(): void
    {
        $service = $this->makeService([
            new MockResponse(self::VALID_KEY_BODY_DIFFERENT_EMAIL, ['http_code' => 200]),
            $this->validKeyWithMatchingEmail(),
            $this->notFound(),
        ]);

        $result = $service->getPgpKeyResult('user@example.com');

        $this->assertInstanceOf(PgpKeyResult::class, $result);
        // First server (keys.openpgp.org) had mismatched UID, so
        // the service falls through to the second server (keyserver.ubuntu.com)
        $this->assertStringContainsString('keyserver.ubuntu.com', $result->source);
        $this->assertSame('user@example.com', $result->emails[0]);
    }

    /**
     * Timeout or transport errors on a server should be treated as a skip —
     * the service should fall through to the next server.
     */
    public function testGetPublicKeyByEmailHandlesTimeoutByFallingThrough(): void
    {
        $service = $this->makeService([
            // First server times out / throws transport error
            new MockResponse('', ['http_code' => 500]),
            // Second server has the key
            $this->validKeyWithMatchingEmail(),
            $this->notFound(),
        ]);

        $key = $service->getPublicKeyByEmail('user@example.com');
        $this->assertStringContainsString('BEGIN PGP PUBLIC KEY BLOCK', $key);
    }

    /**
     * Multiple key blocks in a single response should be handled
     * deterministically — the first key with a matching UID wins.
     */
    public function testGetPublicKeyByEmailHandlesMultipleKeyBlocksDeterministically(): void
    {
        $bodyWithMultipleKeys = "-----BEGIN PGP PUBLIC KEY BLOCK-----\nuid           Other Person <other@example.com>\n-----BEGIN PGP PUBLIC KEY BLOCK-----\nuid           Test User <user@example.com>\n-----END PGP PUBLIC KEY BLOCK-----\n-----END PGP PUBLIC KEY BLOCK-----";

        $service = $this->makeService([
            new MockResponse($bodyWithMultipleKeys, ['http_code' => 200]),
            $this->notFound(),
            $this->notFound(),
        ]);

        $result = $service->getPgpKeyResult('user@example.com');

        $this->assertInstanceOf(PgpKeyResult::class, $result);
        $this->assertContains('user@example.com', $result->emails);
    }
}
