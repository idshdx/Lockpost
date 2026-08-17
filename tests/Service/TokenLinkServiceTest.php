<?php

namespace App\Tests\Service;

use App\Exception\AppException;
use App\Service\TokenLinkService;
use PHPUnit\Framework\TestCase;

class TokenLinkServiceTest extends TestCase
{
    private TokenLinkService $tokenLinkService;
    private string $appSecret = 'test-app-secret-32-characters-long!!';

    protected function setUp(): void
    {
        $this->tokenLinkService = new TokenLinkService($this->appSecret);
    }

    // --- Roundtrip & Basic ---

    public function testGenerateAndValidateRoundtrip(): void
    {
        $email = 'user@example.com';
        $token = $this->tokenLinkService->generateLink($email);
        $this->assertNotEmpty($token);

        $validatedEmail = $this->tokenLinkService->validateLink($token);
        $this->assertSame($email, $validatedEmail);
    }

    public function testValidateLinkIsCaseInsensitiveAndTrimsWhitespace(): void
    {
        $email = 'User@Example.COM';
        $token = $this->tokenLinkService->generateLink($email);

        $validatedEmail = $this->tokenLinkService->validateLink($token);
        $this->assertSame(strtolower(trim($email)), $validatedEmail);
    }

    public function testValidateLinkWithLeadingTrailingWhitespaceInEmail(): void
    {
        $email = '  user@example.com  ';
        $token = $this->tokenLinkService->generateLink($email);

        $validatedEmail = $this->tokenLinkService->validateLink($token);
        $this->assertSame('user@example.com', $validatedEmail);
    }

    // --- Tamper Detection ---

    public function testValidateLinkThrowsOnTamperedToken(): void
    {
        $token = $this->tokenLinkService->generateLink('user@example.com');

        // v2 tokens: "v2" prefix + nonce[24] + ciphertext+mac
        // Decode and flip a byte in the ciphertext (after the nonce)
        $decoded = base64_decode(strtr($token, '-_', '+/'));
        // Skip "v2" (2 bytes) + nonce (24 bytes) = byte 26
        $tampered = substr($decoded, 0, 2) . substr($decoded, 2, 24) . (substr($decoded, 26, 1) ^ "\\x01") . substr($decoded, 27);

        $tamperedToken = rtrim(strtr(base64_encode($tampered), '+/', '-_'), '=');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('tampered');
        $this->tokenLinkService->validateLink($tamperedToken);
    }

    public function testValidateLinkThrowsOnExpiredToken(): void
    {
        $token = $this->generateExpiredToken('user@example.com', time() - 1);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('expired');
        $this->tokenLinkService->validateLink($token);
    }

    public function testValidateLinkThrowsOnGarbageToken(): void
    {
        $this->expectException(AppException::class);
        $this->tokenLinkService->validateLink('!!!not-base64-at-all!!!');
    }

    public function testValidateLinkThrowsOnShortToken(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Invalid');
        $this->tokenLinkService->validateLink('short');
    }

    public function testGenerateLinkProducesDifferentTokensOnEachCall(): void
    {
        $token1 = $this->tokenLinkService->generateLink('user@example.com');
        $token2 = $this->tokenLinkService->generateLink('user@example.com');

        $this->assertNotEquals($token1, $token2);
    }

    public function testValidateLinkThrowsOnEmptyToken(): void
    {
        $this->expectException(AppException::class);
        $this->tokenLinkService->validateLink('');
    }

    // --- Expiration ---

    public function testCustomExpirationPeriodIsRespected(): void
    {
        $shortTtl = 3600; // 1 hour
        $service = new TokenLinkService($this->appSecret, $shortTtl);

        $token = $service->generateLink('user@example.com');
        $validatedEmail = $service->validateLink($token);
        $this->assertSame('user@example.com', $validatedEmail);

        $expiredToken = $this->generateExpiredTokenForService($service, 'user@example.com', time() - 1);
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('expired');
        $service->validateLink($expiredToken);
    }

    public function testGetExpirationPeriodReturnsConfiguredValue(): void
    {
        $serviceDefault = new TokenLinkService($this->appSecret);
        $this->assertSame(604800, $serviceDefault->getExpirationPeriod()); // 7 days default

        $serviceCustom = new TokenLinkService($this->appSecret, 86400);
        $this->assertSame(86400, $serviceCustom->getExpirationPeriod());
    }

    // --- Security Tests ---

    public function testInvalidBase64ThrowsAppException(): void
    {
        $this->expectException(AppException::class);
        $this->tokenLinkService->validateLink('@@@not-valid-base64@@@');
    }

    public function testTamperedIVIsDetected(): void
    {
        $token = $this->tokenLinkService->generateLink('user@example.com');
        $decoded = base64_decode(strtr($token, '-_', '+/'));

        // v2: "v2" (2 bytes) + nonce (24 bytes) + ciphertext
        // Flip a bit in the nonce portion
        $tampered = substr($decoded, 0, 2) . (substr($decoded, 2, 1) ^ "\\x01") . substr($decoded, 3);

        $tamperedToken = rtrim(strtr(base64_encode($tampered), '+/', '-_'), '=');

        $this->expectException(AppException::class);
        $this->tokenLinkService->validateLink($tamperedToken);
    }

    public function testTamperedCiphertextIsDetected(): void
    {
        $token = $this->tokenLinkService->generateLink('user@example.com');
        $decoded = base64_decode(strtr($token, '-_', '+/'));

        // v2: "v2" (2 bytes) + nonce (24 bytes) + ciphertext
        // Flip a bit in the ciphertext (byte 26 onward)
        $tampered = substr($decoded, 0, 26) . (substr($decoded, 26, 1) ^ "\\x01") . substr($decoded, 27);

        $tamperedToken = rtrim(strtr(base64_encode($tampered), '+/', '-_'), '=');

        $this->expectException(AppException::class);
        $this->tokenLinkService->validateLink($tamperedToken);
    }

    public function testMalformedJsonPayloadIsRejected(): void
    {
        $token = $this->buildTokenWithEncryptedData('user@example.com', 'not-json-at-all');

        $this->expectException(AppException::class);
        $this->tokenLinkService->validateLink($token);
    }

    public function testMissingEmailPayloadIsRejected(): void
    {
        $token = $this->buildTokenWithEncryptedData('user@example.com', json_encode([
            'exp'   => time() + 604800,
            'nonce' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR));

        $this->expectException(AppException::class);
        $this->tokenLinkService->validateLink($token);
    }

    public function testMissingExpirationPayloadIsRejected(): void
    {
        $token = $this->buildTokenWithEncryptedData('user@example.com', json_encode([
            'email' => 'user@example.com',
            'nonce' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR));

        $this->expectException(AppException::class);
        $this->tokenLinkService->validateLink($token);
    }

    public function testNonNumericExpirationIsRejected(): void
    {
        $token = $this->buildTokenWithEncryptedData('user@example.com', json_encode([
            'email' => 'user@example.com',
            'exp'   => 'not-a-number',
            'nonce' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR));

        $this->expectException(AppException::class);
        $this->tokenLinkService->validateLink($token);
    }

    public function testSecretRotationInvalidatesTokens(): void
    {
        $token = $this->tokenLinkService->generateLink('user@example.com');

        $rotatedService = new TokenLinkService('different-secret-32-characters!!!');

        $this->expectException(AppException::class);
        $rotatedService->validateLink($token);
    }

    // --- Backward Compatibility ---

    /**
     * Generates a v1 (legacy AES-256-CBC + HMAC) token for backward-compat testing.
     */
    public function testV1TokenStillValidates(): void
    {
        $token = $this->tokenLinkService->generateLinkV1('user@example.com');

        $validatedEmail = $this->tokenLinkService->validateLink($token);
        $this->assertSame('user@example.com', $validatedEmail);
    }

    /**
     * Generates an expired token by directly using the service's internal
     * encryption logic with a past expiration timestamp.
     */
    private function generateExpiredTokenForService(TokenLinkService $service, string $email, int $expiry): string
    {
        $data = json_encode([
            'email' => strtolower(trim($email)),
            'exp'   => $expiry,
            'nonce' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR);

        // Use v2 AEAD to construct the token
        return $this->buildV2TokenForService($service, $data);
    }

    /**
     * Generates a valid v2 token for a given service instance using its
     * internal encryption logic with a past expiration timestamp.
     */
    private function generateExpiredToken(string $email, int $expiry): string
    {
        $data = json_encode([
            'email' => strtolower(trim($email)),
            'exp'   => $expiry,
            'nonce' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR);

        return $this->buildV2TokenForService($this->tokenLinkService, $data);
    }

    /**
     * Builds a token with arbitrary encrypted JSON payload for security testing.
     * Uses v2 AEAD to construct the token with a custom payload.
     */
    private function buildTokenWithEncryptedData(string $email, string $jsonPayload): string
    {
        return $this->buildV2TokenForService($this->tokenLinkService, $jsonPayload);
    }

    /**
     * Internal helper: builds a v2 token using the service's v2 key derivation.
     * Accesses the private deriveV2Key method via reflection.
     */
    private function buildV2TokenForService(TokenLinkService $service, string $payload): string
    {
        $reflection = new \ReflectionClass($service);
        $deriveKey = $reflection->getMethod('deriveV2Key');
        $deriveKey->setAccessible(true);
        $key = $deriveKey->invoke($service);

        $nonce = random_bytes(24);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $payload,
            '',
            $nonce,
            $key
        );

        $body = 'v2' . $nonce . $ciphertext;

        return rtrim(strtr(base64_encode($body), '+/', '-_'), '=');
    }
}
