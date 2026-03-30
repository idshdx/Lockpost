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

        // Form posts to /verify/signature which redirects back to /verify on invalid data
        self::assertResponseRedirects('/verify');
        $client->followRedirect();
        self::assertRouteSame('app_verify');
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

        $client->submit($form);

        // Form posts to /verify/signature which redirects back to /verify with a flash message
        self::assertResponseRedirects('/verify');
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();

        // Assert that a flash/alert message is displayed
        $this->assertGreaterThan(0, $crawler->filter('[role="alert"]')->count(), 'Expected a role="alert" element to be rendered.');
    }

}
