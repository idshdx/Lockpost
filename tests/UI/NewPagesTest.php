<?php

namespace App\Tests\UI;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the /about and /privacy pages.
 *
 * Validates: Requirements 4.1–4.5, 5.1–5.6
 */
class NewPagesTest extends WebTestCase
{
    public function testAboutPageReturns200WithExpectedContent(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about');

        self::assertResponseStatusCodeSame(200);

        $html = $client->getResponse()->getContent();

        self::assertStringContainsString('<h1>About SYM.PGP.ONY</h1>', $html);
        self::assertStringContainsString('What is SYM.PGP.ONY?', $html);
        self::assertStringContainsString('How it works', $html);
        self::assertStringContainsString('Zero storage', $html);
        self::assertStringContainsString('No tracking', $html);
        self::assertStringContainsString('href="/"', $html);
    }

    public function testPrivacyPageReturns200WithExpectedContent(): void
    {
        $client = static::createClient();
        $client->request('GET', '/privacy');

        self::assertResponseStatusCodeSame(200);

        $html = $client->getResponse()->getContent();

        self::assertStringContainsString('<h1>Privacy Policy</h1>', $html);
        self::assertStringContainsString('No server-side message storage', $html);
        self::assertStringContainsString('No cookies or tracking', $html);
        self::assertStringContainsString('How your email address is used', $html);
        self::assertStringContainsString('Tokenised links', $html);
    }

    public function testAboutPageExtendsBase(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about');

        $html = $client->getResponse()->getContent();

        self::assertStringContainsString('role="navigation"', $html);
        self::assertStringContainsString('role="contentinfo"', $html);
        self::assertStringContainsString('href="#main-content"', $html);
    }

    public function testPrivacyPageExtendsBase(): void
    {
        $client = static::createClient();
        $client->request('GET', '/privacy');

        $html = $client->getResponse()->getContent();

        self::assertStringContainsString('role="navigation"', $html);
        self::assertStringContainsString('role="contentinfo"', $html);
        self::assertStringContainsString('href="#main-content"', $html);
    }
}
