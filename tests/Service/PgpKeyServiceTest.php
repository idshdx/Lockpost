<?php
// tests/Service/PgpKeyServiceTest.php

namespace App\Tests\Service;

use App\Exception\AppException;
use App\Service\PgpKeyService;
use PHPUnit\Framework\TestCase;

class PgpKeyServiceTest extends TestCase
{
    private $pgpKeyService;

    protected function setUp(): void
    {
        $this->pgpKeyService = new PgpKeyService();
    }

    public function testVerifyPublicKeyExistsValidEmail(): void
    {
        $email = 'idzer0lis@gmail.com';
        $this->assertTrue($this->pgpKeyService->verifyPublicKeyExists($email));
    }

    public function testVerifyPublicKeyExistsInvalidEmail(): void
    {
        $email = 'invalid-email';
        $this->assertFalse($this->pgpKeyService->verifyPublicKeyExists($email));
    }

    public function testGetPublicKeyByEmailValidEmail(): void
    {
        $email = 'idzer0lis@gmail.com';
        $publicKey = $this->pgpKeyService->getPublicKeyByEmail($email);
        $this->assertNotEmpty($publicKey);
        $this->assertStringContainsString('BEGIN PGP PUBLIC KEY BLOCK', $publicKey);
    }

    public function testGetPublicKeyByEmailInvalidEmail(): void
    {
        $email = 'invalid-email';
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Invalid email address format');
        $this->pgpKeyService->getPublicKeyByEmail($email);
    }

    public function testGetPublicKeyByEmailNoPublicKeyFound(): void
    {
        $email = 'no-public-key-' . uniqid() . '@example.com';
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('No public key found for the provided email address');
        $this->pgpKeyService->getPublicKeyByEmail($email);
    }
}
