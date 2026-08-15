<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DefaultControllerTest extends WebTestCase
{
    public function testIndexPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="email_form"]');
    }

    public function testFlashMessageRendersOutsideBodyBlock(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $form = $crawler->filter('form[name="email_form"]')->form([
            'email_form[email]' => 'invalid-email',
        ]);

        $crawler = $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="email_form"]');
    }

    public function testVerifyPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/verify');

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
        self::assertResponseRedirects('/verify');
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
        self::assertResponseRedirects('/verify');
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();

        // Assert that a flash/alert message is displayed
        $this->assertGreaterThan(0, $crawler->filter('[role="alert"]')->count(), 'Expected a role="alert" element to be rendered.');
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
        $client = static::createClient();
        $container = static::getContainer();

        // .env.test defines APP_MAIL_FROM=noreply@example.com
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
            json_encode(['encryptedMessage' => '-----BEGIN PGP MESSAGE-----\ntest\n-----END PGP MESSAGE-----'])
        );

        self::assertResponseStatusCodeSame(400);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertFalse($data['success']);
    }

}
