<?php

namespace App\Tests\Controller;

use App\Service\PgpSigningService;
use Exception;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DefaultControllerTest extends WebTestCase
{
    private $client;
    private $pgpSigningService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->pgpSigningService = $this->createMock(PgpSigningService::class);
        self::getContainer()->set(PgpSigningService::class, $this->pgpSigningService);
    }

    public function testVerifyPageLoads(): void
    {
        $crawler = $this->client->request('GET', '/verify');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="pgp_verify_form"]');
    }

    public function testVerifyValidSignature(): void
    {
        $this->pgpSigningService
            ->expects($this->once())
            ->method('verifySignature')
            ->willReturn(true);

        $formData = [
            'pgp_verify_form' => [
                'message' => 'Test message',
                'signature' => '-----BEGIN PGP SIGNATURE-----\nTest Signature\n-----END PGP SIGNATURE-----',
                'public_key' => '-----BEGIN PGP PUBLIC KEY BLOCK-----\nTest Public Key\n-----END PGP PUBLIC KEY BLOCK-----'
            ]
        ];

        $this->client->request('POST', '/verify', $formData);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-success');
        self::assertSelectorTextContains('.alert-success', 'Message signature is valid');
    }

    public function testVerifyInvalidSignature(): void
    {
        $this->pgpSigningService
            ->expects($this->once())
            ->method('verifySignature')
            ->willReturn(false);

        $formData = [
            'pgp_verify_form' => [
                'message' => 'Test message',
                'signature' => '-----BEGIN PGP SIGNATURE-----\nInvalid Signature\n-----END PGP SIGNATURE-----',
                'public_key' => '-----BEGIN PGP PUBLIC KEY BLOCK-----\nTest Public Key\n-----END PGP PUBLIC KEY BLOCK-----'
            ]
        ];

        $this->client->request('POST', '/verify', $formData);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-warning');
        self::assertSelectorTextContains('.alert-warning', 'Message signature is invalid');
    }

    public function testVerifyWithError(): void
    {
        $this->pgpSigningService
            ->expects($this->once())
            ->method('verifySignature')
            ->willThrowException(new Exception('Verification error'));

        $formData = [
            'pgp_verify_form' => [
                'message' => 'Test message',
                'signature' => '-----BEGIN PGP SIGNATURE-----\nTest Signature\n-----END PGP SIGNATURE-----',
                'public_key' => '-----BEGIN PGP PUBLIC KEY BLOCK-----\nTest Public Key\n-----END PGP PUBLIC KEY BLOCK-----'
            ]
        ];

        $this->client->request('POST', '/verify', $formData);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-danger');
        self::assertSelectorTextContains('.alert-danger', 'Error verifying message');
    }

    public function testInvalidFormSubmission(): void
    {
        $formData = [
            'pgp_verify_form' => [
                'message' => '',
                'signature' => '',
                'public_key' => ''
            ]
        ];

        $this->client->request('POST', '/verify', $formData);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.invalid-feedback');
    }

    public function testFallbackForMissingRoutes(): void
    {
        // Testing the rendering with a missing route to verify graceful handling.
        // This ensures missing routes like 'app_public_key' do not break the tests.

        $crawler = $this->client->request('GET', '/verify');

        self::assertResponseIsSuccessful();
        // Ensure no errors are visible on the page
        self::assertStringNotContainsString('Unable to generate a URL for the named route', $this->client->getResponse()->getContent());
    }
}
