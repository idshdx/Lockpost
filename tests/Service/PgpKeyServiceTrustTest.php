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
    /**
     * Loads a real, gpg-generated armored key from tests/Fixtures.
     *
     * Real keys are used (rather than synthetic armor) because the service now
     * decodes the base64 body and reads the actual UID packets, so a plaintext
     * fixture would no longer exercise the real matching path.
     */
    private function fixture(string $name): string
    {
        $path = __DIR__ . '/../Fixtures/' . $name;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Missing fixture: $path");

        return $contents;
    }

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
        return new MockResponse($this->fixture('key_user.asc'), ['http_code' => 200]);
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
            new MockResponse($this->fixture('key_other.asc'), ['http_code' => 200]),
            $this->validKeyWithMatchingEmail(),
            $this->notFound(),
        ]);

        $key = $service->getPublicKeyByEmail('user@example.com');

        // The mismatched key from the first server must be skipped and the
        // matching key from the second server returned unchanged.
        $this->assertStringContainsString('BEGIN PGP PUBLIC KEY BLOCK', $key);
        $this->assertSame(trim($this->fixture('key_user.asc')), $key);
    }

    /**
     * If the only key returned has a mismatched UID, the service
     * should throw AppException (key not found for this email).
     */
    public function testGetPublicKeyByEmailThrowsWhenOnlyMismatchedKeysReturned(): void
    {
        $service = $this->makeService([
            new MockResponse($this->fixture('key_other.asc'), ['http_code' => 200]),
            $this->notFound(),
            $this->notFound(),
        ]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('No public key found for the provided email address');
        $service->getPublicKeyByEmail('user@example.com');
    }

    /**
     * Regression test for the key-lookup bug: an email must be matched from the
     * key's actual UID packet, not from keyserver-added armor metadata.
     *
     * keys.openpgp.org adds a "Comment: Name <email>" header, but
     * keyserver.ubuntu.com does not (it emits "Comment: Hostname:"). A regex over
     * the armor text therefore only ever matched openpgp.org results. The real
     * fixtures below carry no Comment header, so this only passes when the UID
     * packet is genuinely parsed.
     */
    public function testEmailIsMatchedFromUidPacketWithoutArmorCommentHeader(): void
    {
        // key_other.asc has no armor Comment header and no plaintext email —
        // the address lives only inside the base64-encoded UID packet.
        $armor = $this->fixture('key_other.asc');
        $this->assertStringNotContainsString('Comment:', $armor);
        $this->assertStringNotContainsString('other@example.com', $armor);

        $service = $this->makeService([
            new MockResponse($armor, ['http_code' => 200]),
            $this->notFound(),
            $this->notFound(),
        ]);

        $result = $service->getPgpKeyResult('other@example.com');

        $this->assertContains('other@example.com', $result->emails);
    }

    /**
     * A key whose UID carries no email address at all must never match an
     * email search, even though the key itself is valid and decodable.
     */
    public function testKeyWithNoEmailUidIsRejected(): void
    {
        // key_noemail.asc has the UID "No Email UID" — valid packet, no address.
        $service = $this->makeService([
            new MockResponse($this->fixture('key_noemail.asc'), ['http_code' => 200]),
            $this->notFound(),
            $this->notFound(),
        ]);

        $this->expectException(AppException::class);
        $service->getPublicKeyByEmail('nobody@example.com');
    }

    /**
     * verifyPublicKeyExists returns true only when a key with matching UID is found.
     */
    public function testVerifyPublicKeyExistsReturnsFalseForMismatchedUid(): void
    {
        $service = $this->makeService([
            new MockResponse($this->fixture('key_other.asc'), ['http_code' => 200]),
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
            new MockResponse($this->fixture('key_other.asc'), ['http_code' => 200]),
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
     * deterministically — every block is scanned and the first one with a
     * matching UID wins. Keyservers return one block per matching key.
     */
    public function testGetPublicKeyByEmailHandlesMultipleKeyBlocksDeterministically(): void
    {
        // Two complete, real key blocks concatenated: a non-matching key first,
        // the matching key second. The scan must find the matching one.
        $bodyWithMultipleKeys = trim($this->fixture('key_other.asc')) . "\n"
            . trim($this->fixture('key_user.asc'));

        $service = $this->makeService([
            new MockResponse($bodyWithMultipleKeys, ['http_code' => 200]),
            $this->notFound(),
            $this->notFound(),
        ]);

        $result = $service->getPgpKeyResult('user@example.com');

        $this->assertInstanceOf(PgpKeyResult::class, $result);
        $this->assertContains('user@example.com', $result->emails);
        // The first block (other@example.com) must be skipped in favour of the
        // matching block, so its UID must not leak into the result.
        $this->assertNotContains('other@example.com', $result->emails);
    }
}
