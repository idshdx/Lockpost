<?php

namespace App\Tests\DependencyInjection;

use App\DependencyInjection\AppSecretValidationPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class AppSecretValidationPassTest extends TestCase
{
    private AppSecretValidationPass $pass;
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->pass = new AppSecretValidationPass();
        $this->container = new ContainerBuilder();
        $this->container->setParameter('kernel.environment', 'prod');
        $this->container->setParameter('kernel.secret', 'change-me-to-a-random-32-char-string');
    }

    public function testShortSecretInProdThrows(): void
    {
        $this->container->setParameter('kernel.secret', 'short');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_SECRET is not secure');

        $this->pass->process($this->container);
    }

    public function testInsecureDefaultSecretInProdThrows(): void
    {
        $this->container->setParameter('kernel.secret', 'change-me-to-a-random-32-char-string');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('insecure default value');

        $this->pass->process($this->container);
    }

    public function testRepeatedCharacterSecretThrows(): void
    {
        $this->container->setParameter('kernel.secret', str_repeat('a', 64));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('single repeated character');

        $this->pass->process($this->container);
    }

    public function testStrongSecretInProdPasses(): void
    {
        $strongSecret = bin2hex(random_bytes(32));
        $this->container->setParameter('kernel.secret', $strongSecret);

        // Should not throw.
        $this->pass->process($this->container);

        $this->addToAssertionCount(1);
    }

    public function testShortSecretInDevDoesNotThrow(): void
    {
        $this->container->setParameter('kernel.environment', 'dev');
        $this->container->setParameter('kernel.secret', 'short');

        $this->pass->process($this->container);

        $this->addToAssertionCount(1);
    }

    public function testShortSecretInTestDoesNotThrow(): void
    {
        $this->container->setParameter('kernel.environment', 'test');
        $this->container->setParameter('kernel.secret', 'short');

        $this->pass->process($this->container);

        $this->addToAssertionCount(1);
    }

    public function test32CharHexStringIsRejected(): void
    {
        // 32 hex chars = only 16 bytes of entropy
        $this->container->setParameter('kernel.secret', str_repeat('a1', 16));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('16 bytes of entropy');

        $this->pass->process($this->container);
    }
}
