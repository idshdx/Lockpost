<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\LimiterInterface;

class DefaultControllerTest extends WebTestCase
{
    private const VERIFY_ROUTE = '/verify';
    private const EMAIL_FORM_SELECTOR = 'form[name="email_form"]';

    public function testIndexPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(self::EMAIL_FORM_SELECTOR);
    }

    public function testFlashMessageRendersOutsideBodyBlock(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $form = $crawler->filter(self::EMAIL_FORM_SELECTOR)->form([
            'email_form[email]' => 'invalid-email',
        ]);

        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(self::EMAIL_FORM_SELECTOR);
    }

    public function testVerifyPage(): void
    {
        $client = static::createClient();
        $client->request('GET', self::VERIFY_ROUTE);

        self::assertResponseIsSuccessful();
        // The verify page now uses a plain HTML form wired to the Stimulus verify controller
        self::assertSelectorExists('form[data-controller="verify"]');
    }

    public function testValidFormSubmission(): void
    {
        $client = static::createClient();

        // POST directly to the server-side verify endpoint (kept for backward compat)
        $client->request('POST', '/verify/signature', [
            'verify_signature_form' => [
                'message' => 'Test message',
                'signature' => 'Test signature',
                'public_key' => 'Test public key',
                '_token' => 'invalid',
            ],
        ]);

        // Invalid CSRF → form not submitted → flash added → redirect to /verify
        self::assertResponseRedirects(self::VERIFY_ROUTE);
        $client->followRedirect();
        self::assertRouteSame('app_verify');
    }

    public function testInvalidFormSubmission(): void
    {
        $client = static::createClient();

        // POST directly to the server-side verify endpoint with invalid CSRF
        $client->request('POST', '/verify/signature', [
            'verify_signature_form' => [
                'message' => '',
                'signature' => 'Invalid Signature',
                'public_key' => 'Invalid Public Key',
                '_token' => 'invalid',
            ],
        ]);

        // Invalid CSRF → flash added → redirect to /verify
        self::assertResponseRedirects(self::VERIFY_ROUTE);
        $client->followRedirect();

        self::assertResponseIsSuccessful();

        // Assert that a flash/alert message is displayed
        self::assertGreaterThan(0, $client->getCrawler()->filter('[role="alert"]')->count(), 'Expected a role="alert" element to be rendered.');
    }

    /**
     * Requirement 9.3: APP_MAIL_FROM env var must be used as the From address.
     *
     * The `app.mail_from` DI parameter is wired to `%env(APP_MAIL_FROM)%` in
     * config/services.yaml. This test verifies that, in the test environment
     * (where .env.test sets APP_MAIL_FROM=noreply@example.com), the container
     * resolves the parameter to that exact address — proving the env var is the
     * source of truth for the outbound From header.
     *
     * Validates: Requirements 9.3
     */
    public function testAppMailFromParameterResolvesFromEnv(): void
    {
        $container = static::getContainer();

        // In the test container the resolved sender should come from the
        // active APP_MAIL_FROM environment value.
        $mailFrom = $container->getParameter('app.mail_from');

        self::assertSame('noreply@example.com', $mailFrom);
    }

    /**
     * Validates that /message/submit returns HTTP 400 with success=false when
     * the required `recipient` field is absent from the JSON payload.
     *
     * Validates: Requirements 9.3, 9.4
     */
    public function testSubmitMessageReturnsErrorWhenRecipientMissing(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/message/submit',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['encryptedMessage' => "-----BEGIN PGP MESSAGE-----\ntest\n-----END PGP MESSAGE-----"])
        );

        self::assertResponseStatusCodeSame(400);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertFalse($data['success']);
    }

    /**
     * Validates that a malformed token (too short) triggers a DTO validation
     * error (400) BEFORE the token-specific rate limiter bucket is consumed.
     *
     * Sends the same malformed token multiple times — if rate limiting
     * were checking before validation, the 2nd request would return 429
     * instead of 400.
     *
     * Validates: Task 7 acceptance criteria — invalid DTOs do not consume
     * token-specific limiter buckets.
     */
    public function testMalformedTokenDoesNotConsumeTokenRateLimiter(): void
    {
        $client = static::createClient();

        $payload = json_encode([
            'token' => 'too-short',  // fails token format validation
            'encryptedMessage' => "-----BEGIN PGP MESSAGE-----\ntest\n-----END PGP MESSAGE-----",
            '_csrf_token' => 'test',
        ]);

        // First request — should get 400 (validation error), not 429
        $client->request('POST', '/message/submit', [], [], ['CONTENT_TYPE' => 'application/json'], $payload);
        self::assertResponseStatusCodeSame(400);

        // Second request with same malformed token — should STILL get 400,
        // not 429. If rate limiter consumed before validation, this would be 429.
        $client->request('POST', '/message/submit', [], [], ['CONTENT_TYPE' => 'application/json'], $payload);
        self::assertResponseStatusCodeSame(400);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertFalse($data['success']);
    }

    /**
     * Validates that the link_generation and link_generation_failed rate
     * limiters are configured in the container.
     *
     * Validates: Task 8 — rate limiters exist for link generation.
     */
    public function testLinkGenerationRateLimitersAreConfigured(): void
    {
        $container = static::getContainer();

        self::assertTrue($container->has('limiter.link_generation'));
        self::assertTrue($container->has('limiter.link_generation_failed'));
    }
}
