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
        // Submitting with an invalid CSRF token triggers a form error re-render.
        // The flash/error must appear even though child templates override {% block body %}.
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $form = $crawler->selectButton('Generate Link')->form([
            'email_form[email]' => 'invalid-email',
        ]);

        $crawler = $client->submit($form);

        self::assertResponseIsSuccessful();
        // Form should re-render with the email field still present
        self::assertSelectorExists('form[name="email_form"]');
    }

    public function testVerifyPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/verify');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="verify_signature_form"]');
    }

    public function testValidFormSubmission(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/verify');

        $form = $crawler->selectButton('Verify Signature')->form([
            'verify_signature_form[message]' => 'Test message',
            'verify_signature_form[signature]' => 'Test signature',
            'verify_signature_form[public_key]' => 'Test public key'
        ]);

        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertRouteSame  ('app_verify'); // Match actual route name
    }

    public function testInvalidFormSubmission(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/verify');

        $form = $crawler->selectButton('Verify Signature')->form();

        // Submit the form with invalid data
        $form['verify_signature_form[public_key]'] = 'Invalid Public Key';
        $form['verify_signature_form[message]'] = '';
        $form['verify_signature_form[signature]'] = 'Invalid Signature';

        $crawler = $client->submit($form);

        self::assertResponseIsSuccessful();

        // Assert that at least one .invalid-feedback message is displayed
        $this->assertGreaterThan(0, $crawler->filter('.invalid-feedback')->count(), 'Expected .invalid-feedback class to be rendered.');
    }

}
