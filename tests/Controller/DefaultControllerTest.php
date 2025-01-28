<?php

namespace App\Tests\Controller;

use App\Form\PgpVerifySignatureFormType;
use App\Service\PgpSigningService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DefaultControllerTest extends WebTestCase
{
    private $client;
    private $pgpSigningService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->pgpSigningService = $this->createMock(PgpSigningService::class);
        self::getContainer()->set('App\\Service\\PgpSigningService', $this->pgpSigningService);
    }

    public function testVerifyPageLoads(): void
    {
        $crawler = $this->client->request('GET', '/verifySignaturePage');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="pgp_verify_form"]');
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

        $this->client->request('POST', '/verifySignaturePage', $formData);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.alert-success');
        $this->assertSelectorTextContains('.alert-success', 'Message signature is valid');
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

        $this->client->request('POST', '/verifySignaturePage', $formData);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.alert-warning');
        $this->assertSelectorTextContains('.alert-warning', 'Message signature is invalid');
    }

    public function testVerifyWithError(): void
    {
        $this->pgpSigningService
            ->expects($this->once())
            ->method('verifySignature')
            ->willThrowException(new \Exception('Verification error'));

        $formData = [
            'pgp_verify_form' => [
                'message' => 'Test message',
                'signature' => '-----BEGIN PGP SIGNATURE-----\nTest Signature\n-----END PGP SIGNATURE-----',
                'public_key' => '-----BEGIN PGP PUBLIC KEY BLOCK-----\nTest Public Key\n-----END PGP PUBLIC KEY BLOCK-----'
            ]
        ];

        $this->client->request('POST', '/verifySignaturePage', $formData);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.alert-danger');
        $this->assertSelectorTextContains('.alert-danger', 'Error verifying message');
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

        $this->client->request('POST', '/verifySignaturePage', $formData);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.invalid-feedback');
    }
}
