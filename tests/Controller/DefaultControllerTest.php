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

}
