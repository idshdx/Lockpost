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

    public function testValidateLinkThrowsOnTamperedToken(): void
    {
        $token = $this->tokenLinkService->generateLink('user@example.com');

        // Decode, flip one bit in the HMAC portion, re-encode
        $decoded = base64_decode(strtr($token, '-_', '+/'));

        // Flip a bit in the HMAC (first 32 bytes)
        $tampered = substr($decoded, 0, 32) ^ "\x01";
        $tampered = $tampered . substr($decoded, 32);

        $tamperedToken = rtrim(strtr(base64_encode($tampered), '+/', '-_'), '=');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('tampered');
        $this->tokenLinkService->validateLink($tamperedToken);
    }

    public function testValidateLinkThrowsOnExpiredToken(): void
    {
        // Generate a token with expiration in the past
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

        // Tokens should differ because IVs and nonces are random
        $this->assertNotEquals($token1, $token2);
    }

    public function testValidateLinkThrowsOnEmptyToken(): void
    {
        $this->expectException(AppException::class);

        $this->tokenLinkService->validateLink('');
    }

    /**
     * Validates that a token generated with a custom expiration period
     * is valid when fresh and expires when the period elapses.
     *
     * Validates: Task 9 — token lifetime is configurable.
     */
    public function testCustomExpirationPeriodIsRespected(): void
    {
        $shortTtl = 3600; // 1 hour
        $service = new TokenLinkService($this->appSecret, $shortTtl);

        $token = $service->generateLink('user@example.com');
        $validatedEmail = $service->validateLink($token);
        $this->assertSame('user@example.com', $validatedEmail);

        // Generate an expired token with the same short TTL
        $expiredToken = $this->generateExpiredTokenForService($service, 'user@example.com', time() - 1);
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('expired');
        $service->validateLink($expiredToken);
    }

    /**
     * Validates that getExpirationPeriod returns the configured value.
     */
    public function testGetExpirationPeriodReturnsConfiguredValue(): void
    {
        $serviceDefault = new TokenLinkService($this->appSecret);
        $this->assertSame(604800, $serviceDefault->getExpirationPeriod()); // 7 days default

        $serviceCustom = new TokenLinkService($this->appSecret, 86400);
        $this->assertSame(86400, $serviceCustom->getExpirationPeriod());
    }

    // --- Security Test Suite (Task 23) ---

    /**
     * Invalid base64 should be rejected without leaking internal state.
     */
    public function testInvalidBase64ThrowsAppException(): void
    {
        $this->expectException(AppException::class);
        $this->tokenLinkService->validateLink('@@@not-valid-base64@@@');
    }

    /**
     * Tampering with the IV (first 16 bytes of the payload, after HMAC) should
     * be detected because it changes the ciphertext and the HMAC will not match.
     */
    public function testTamperedIVIsDetected(): void
    {
        $token = $this->tokenLinkService->generateLink('user@example.com');
        $decoded = base64_decode(strtr($token, '-_', '+/'));

        // HMAC is 32 bytes, IV is the next 16 bytes — flip a bit in the IV
        $tampered = substr($decoded, 0, 32) . (substr($decoded, 32, 16) ^ "\x01") . substr($decoded, 48);

        $tamperedToken = rtrim(strtr(base64_encode($tampered), '+/', '-_'), '=');

        $this->expectException(AppException::class);
        $this->tokenLinkService->validateLink($tamperedToken);
    }

    /**
     * Tampering with the ciphertext (after HMAC + IV) should be detected.
     */
    public function testTamperedCiphertextIsDetected(): void
    {
        $token = $this->tokenLinkService->generateLink('user@example.com');
        $decoded = base64_decode(strtr($token, '-_', '+/'));

        // HMAC is 32 bytes, IV is 16 bytes, ciphertext starts at byte 48
        $tampered = substr($decoded, 0, 48) . (substr($decoded, 48, 1) ^ "\x01") . substr($decoded, 49);

        $tamperedToken = rtrim(strtr(base64_encode($tampered), '+/', '-_'), '=');

        $this->expectException(AppException::class);
        $this->tokenLinkService->validateLink($tamperedToken);
    }

    /**
     * Malformed JSON payload inside the encrypted data should be rejected.
     */
    public function testMalformedJsonPayloadIsRejected(): void
    {
        $token = $this->buildTokenWithEncryptedData('user@example.com', 'not-json-at-all');

        $this->expectException(AppException::class);
        $this->tokenLinkService->validateLink($token);
    }

    /**
     * Missing 'email' key in the JSON payload should be rejected.
     */
    public function testMissingEmailPayloadIsRejected(): void
    {
        $token = $this->buildTokenWithEncryptedData('user@example.com', json_encode([
            'exp'   => time() + 604800,
            'nonce' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR));

        $this->expectException(AppException::class);
        $this->tokenLinkService->validateLink($token);
    }

    /**
     * Missing 'exp' key in the JSON payload should be rejected.
     */
    public function testMissingExpirationPayloadIsRejected(): void
    {
        $token = $this->buildTokenWithEncryptedData('user@example.com', json_encode([
            'email' => 'user@example.com',
            'nonce' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR));

        $this->expectException(AppException::class);
        $this->tokenLinkService->validateLink($token);
    }

    /**
     * Non-numeric 'exp' value should be rejected (should not default to valid).
     */
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

    /**
     * Secret rotation should invalidate all previously issued tokens.
     */
    public function testSecretRotationInvalidatesTokens(): void
    {
        $token = $this->tokenLinkService->generateLink('user@example.com');

        // Create a service with a different secret
        $rotatedService = new TokenLinkService('different-secret-32-characters!!!');

        $this->expectException(AppException::class);
        $rotatedService->validateLink($token);
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

        $cipher = 'aes-256-cbc';
        $ivlen = openssl_cipher_iv_length($cipher);
        $iv = random_bytes($ivlen);

        $reflection = new \ReflectionClass($service);
        $deriveKey = $reflection->getMethod('deriveKey');
        $deriveKey->setAccessible(true);

        $encKey = $deriveKey->invoke($service, 'lockpost-token-enc');
        $encrypted = openssl_encrypt($data, $cipher, $encKey, OPENSSL_RAW_DATA, $iv);

        $payload = $iv . $encrypted;
        $hmacKey = $deriveKey->invoke($service, 'lockpost-token-auth');
        $hmac = hash_hmac('sha256', $payload, $hmacKey, true);

        return rtrim(strtr(base64_encode($hmac . $payload), '+/', '-_'), '=');
    }

    /**
     * Generates a valid token for a given service instance using its
     * internal encryption logic with a past expiration timestamp.
     */
    private function generateExpiredToken(string $email, int $expiry): string
    {
        $service = new TokenLinkService($this->appSecret);

        // Manually construct an expired token using the same logic as generateLink
        $data = json_encode([
            'email' => strtolower(trim($email)),
            'exp'   => $expiry,
            'nonce' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR);

        $cipher = 'aes-256-cbc';
        $ivlen = openssl_cipher_iv_length($cipher);
        $iv = random_bytes($ivlen);

        // Access deriveKey via reflection — intentionally accessing a private method.
        // This is a test-only scenario; production code never needs this.
        $reflection = new \ReflectionClass($service);
        $deriveKey = $reflection->getMethod('deriveKey');
        $deriveKey->setAccessible(true);

        $encKey = $deriveKey->invoke($service, 'lockpost-token-enc');
        $encrypted = openssl_encrypt($data, $cipher, $encKey, OPENSSL_RAW_DATA, $iv);

        $payload = $iv . $encrypted;
        $hmacKey = $deriveKey->invoke($service, 'lockpost-token-auth');
        $hmac = hash_hmac('sha256', $payload, $hmacKey, true);

        return rtrim(strtr(base64_encode($hmac . $payload), '+/', '-_'), '=');
    }

    /**
     * Builds a token with arbitrary encrypted JSON payload for security testing.
     * Uses the same internal crypto logic as generateLink but allows overriding
     * the JSON payload (e.g., malformed JSON, missing fields, non-numeric exp).
     */
    private function buildTokenWithEncryptedData(string $email, string $jsonPayload): string
    {
        $service = $this->tokenLinkService;

        $cipher = 'aes-256-cbc';
        $ivlen = openssl_cipher_iv_length($cipher);
        $iv = random_bytes($ivlen);

        $reflection = new \ReflectionClass($service);
        $deriveKey = $reflection->getMethod('deriveKey');
        $deriveKey->setAccessible(true);

        $encKey = $deriveKey->invoke($service, 'lockpost-token-enc');
        $encrypted = openssl_encrypt($jsonPayload, $cipher, $encKey, OPENSSL_RAW_DATA, $iv);

        $payload = $iv . $encrypted;
        $hmacKey = $deriveKey->invoke($service, 'lockpost-token-auth');
        $hmac = hash_hmac('sha256', $payload, $hmacKey, true);

        return rtrim(strtr(base64_encode($hmac . $payload), '+/', '-_'), '=');
    }
}
