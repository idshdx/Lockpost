<?php

namespace App\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

class SymfonyBootstrapTest extends WebTestCase
{
    public function testKernelBootsSuccessfully(): void
    {
        $kernel = self::bootKernel();
        $this->assertInstanceOf(KernelInterface::class, $kernel);
        $this->assertTrue($kernel->isDebug());
        $this->assertEquals('test', $kernel->getEnvironment());
    }

    public function testBasicServicesAreAvailable(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();

        $this->assertTrue($container->has('router'));
        $this->assertTrue($container->has('request_stack'));
        $this->assertTrue($container->has('event_dispatcher'));
    }

    public function testDefaultRouteIsAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
    }
}