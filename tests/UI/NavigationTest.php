<?php

namespace App\Tests\UI;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for navigation properties from the ui-redesign spec.
 */
class NavigationTest extends WebTestCase
{
    // Feature: ui-redesign, Property 3: Active Nav Link Marked with aria-current

    /**
     * @dataProvider navRouteProvider
     */
    public function testActiveNavLinkMarkedWithAriaCurrent(string $url, string $expectedLinkText): void
    {
        $client = static::createClient();
        $client->request('GET', $url);
        $html = $client->getResponse()->getContent();

        // Exactly one aria-current="page" in the whole page
        self::assertSame(
            1,
            substr_count($html, 'aria-current="page"'),
            "Page $url: expected exactly one aria-current=\"page\" attribute"
        );

        // The link with aria-current="page" must be the one for the current route.
        // The template renders aria-current before href, then closes the tag, then the text:
        //   aria-current="page"\n   href="...">LinkText</a>
        self::assertMatchesRegularExpression(
            '/aria-current="page"[^>]*>' . preg_quote($expectedLinkText, '/') . '<\/a>/s',
            $html,
            "Page $url: aria-current=\"page\" is not on the \"$expectedLinkText\" link"
        );
    }

    public function navRouteProvider(): array
    {
        return [
            ['/', 'Home'],
            ['/verify', 'Verify'],
            ['/about', 'About'],
            ['/privacy', 'Privacy'],
        ];
    }
}
