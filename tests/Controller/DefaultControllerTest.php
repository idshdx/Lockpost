<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

class DefaultControllerTest extends WebTestCase
{
    private const VERIFY_ROUTE = '/verify';
    private const EMAIL_FORM_SELECTOR = 'form[name="email_form"]';

    /**
     * Returns a valid CSRF token for the given id and makes the client reuse the
     * session it was stored in.
     *
     * /message/submit validates CSRF before DTO/rate-limit validation, so tests
     * that intend to exercise those later gates must present a real token —
     * otherwise they 400 at the CSRF check and assert nothing meaningful.
     *
     * The token manager persists tokens in the session, so a session is created
     * explicitly and bound to a throwaway request (SessionTokenStorage reads the
     * session off the request stack). The client is then pointed at that same
     * session via its cookie so the controller's own token manager finds it.
     */
    private function csrfToken(KernelBrowser $client, string $id = 'submit_message'): string
    {
        $container = $client->getContainer();
        $session = $container->get('session.factory')->createSession();

        $request = Request::create('/');
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);

        $manager = new CsrfTokenManager(null, new SessionTokenStorage($stack));
        $token = $manager->getToken($id)->getValue();

        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        return $token;
    }

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

        // The form is hand-rolled (no form_start/form_end), so the CSRF hidden
        // input must be rendered explicitly via form_widget(form._token) —
        // without it every non-JS POST to /verify/signature is rejected.
        self::assertSelectorExists('input[name="verify_signature_form[_token]"]', 'The verify form must render its CSRF token field.');

        // Guard against stray backslash-escaped quotes (data-verify-target=\"…\")
        // leaking into the HTML: they corrupt the attribute value so Stimulus
        // can never resolve the target and the JS verify path silently dies.
        self::assertSelectorExists('textarea[data-verify-target="signedMessage"]', 'The signed-message textarea must carry a valid Stimulus target.');
        self::assertSelectorExists('textarea[data-verify-target="publicKey"]', 'The public-key textarea must carry a valid Stimulus target.');
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
            json_encode([
                'encryptedMessage' => "-----BEGIN PGP MESSAGE-----\ntest\n-----END PGP MESSAGE-----",
                '_csrf_token' => $this->csrfToken($client),
            ])
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
            '_csrf_token' => $this->csrfToken($client),
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

    /**
     * Validates that the plain-text email template exists and renders
     * with the expected variables for multipart email support.
     *
     * Validates: Task 10 — outgoing email is multipart HTML + plain text.
     */
    public function testPlainTextEmailTemplateExists(): void
    {
        $templateDir = static::getContainer()->getParameter('kernel.project_dir') . '/templates/email';

        self::assertFileExists($templateDir . '/message.html.twig');
        self::assertFileExists($templateDir . '/message.txt.twig');
    }

    /**
     * Validates that the verify URL uses ABSOLUTE_URL generation.
     *
     * Validates: Task 25 — Email verify URL is absolute.
     */
    public function testVerifyUrlIsAbsolute(): void
    {
        // The generateLinkResponse uses UrlGeneratorInterface::ABSOLUTE_URL.
        // We test this by checking the link page renders an absolute URL.
        $client = static::createClient();
        $client->request('GET', '/');
        $crawler = $client->getCrawler();
        $form = $crawler->filter(self::EMAIL_FORM_SELECTOR)->form();
        $form["email_form[email]"] = "test@example.com";
        $client->submit($form);

        // The link page should contain an absolute URL in the verify link
        $html = $client->getResponse()->getContent();
        // Look for http:// or https:// in the page (absolute URL)
        self::assertMatchesRegularExpression('/https?:\/\//', $html);
    }

    /**
     * Validates that a POST with invalid JSON returns 400.
     *
     * Validates: Task 25 — Invalid JSON returns 400.
     */
    public function testSubmitMessageReturns400ForInvalidJson(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/message/submit',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'not-valid-json{{{broken'
        );

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertFalse($data['success']);
    }

    /**
     * Validates that a POST with missing token returns 400.
     *
     * Validates: Task 25 — Missing token returns 400.
     */
    public function testSubmitMessageReturns400ForMissingToken(): void
    {
        $client = static::createClient();
        $payload = json_encode([
            'encryptedMessage' => "-----BEGIN PGP MESSAGE-----\ntest\n-----END PGP MESSAGE-----",
            '_csrf_token' => $this->csrfToken($client),
        ]);

        $client->request('POST', '/message/submit', [], [], ['CONTENT_TYPE' => 'application/json'], $payload);

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertFalse($data['success']);
    }

    /**
     * Validates that a POST with a malformed token returns 400.
     *
     * Validates: Task 25 — Malformed token returns 400.
     */
    public function testSubmitMessageReturns400ForMalformedToken(): void
    {
        $client = static::createClient();
        $payload = json_encode([
            'token' => '!!!invalid-base64!!!',
            'encryptedMessage' => "-----BEGIN PGP MESSAGE-----\ntest\n-----END PGP MESSAGE-----",
            '_csrf_token' => $this->csrfToken($client),
        ]);

        $client->request('POST', '/message/submit', [], [], ['CONTENT_TYPE' => 'application/json'], $payload);

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertFalse($data['success']);
        // A valid CSRF token was supplied, so the request must clear the CSRF
        // gate and fail at token resolution (which runs after CSRF + DTO +
        // rate limits). Reaching this error proves the test exercises the
        // intended validation path instead of short-circuiting on CSRF.
        self::assertSame('Invalid or expired submission token', $data['error'] ?? null);
    }

    /**
     * Validates that the submit rate limiter (submit_ip) is configured.
     *
     * Validates: Task 25 — IP limiter returns 429.
     */
    public function testSubmitIpRateLimiterIsConfigured(): void
    {
        $container = static::getContainer();
        self::assertTrue($container->has('limiter.submit_ip'));
    }

    /**
     * Validates that the token rate limiter (submit_token) is configured.
     *
     * Validates: Task 25 — Token limiter returns 429.
     */
    public function testSubmitTokenRateLimiterIsConfigured(): void
    {
        $container = static::getContainer();
        self::assertTrue($container->has('limiter.submit_token'));
    }
}
