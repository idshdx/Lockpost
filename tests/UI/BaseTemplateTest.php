<?php

namespace App\Tests\UI;

use App\Service\TokenLinkService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for base template properties that must hold on every page.
 *
 * Each test method covers one correctness property from the ui-redesign design doc.
 * Routes tested: /, /about, /privacy, /verify
 * The /submit/{token} route is included where feasible (P1 external assets check).
 */
class BaseTemplateTest extends WebTestCase
{
    /**
     * Returns the list of standard routes to test across all property assertions.
     *
     * @return string[]
     */
    private function getTestRoutes(): array
    {
        return ['/', '/about', '/privacy', '/verify'];
    }

    /**
     * Generates a valid /submit/{token} URL using TokenLinkService from the container.
     */
    private function getSubmitUrl(): string
    {
        $token = static::getContainer()->get(TokenLinkService::class)->generateLink('test@example.com');
        return '/submit/' . $token;
    }

    // Feature: ui-redesign, Property 1: No External Asset References
    // For any page rendered by the application, the resulting HTML SHALL contain
    // no <link> or <script> tag whose src or href attribute points to an external domain.
    // Validates: Requirements 1.4
    public function testNoExternalAssetReferences(): void
    {
        $client = static::createClient();

        // Build full route list including /submit/{token}
        $routes = $this->getTestRoutes();
        $routes[] = $this->getSubmitUrl();

        foreach ($routes as $url) {
            $client->request('GET', $url);
            $html = $client->getResponse()->getContent();

            // Assert no <link> tag has href pointing to an external domain
            self::assertDoesNotMatchRegularExpression(
                '/<link[^>]+href=["\']https?:\/\//i',
                $html,
                "Page $url: found a <link> tag with an external href"
            );

            // Assert no <script> tag has src pointing to an external domain
            self::assertDoesNotMatchRegularExpression(
                '/<script[^>]+src=["\']https?:\/\//i',
                $html,
                "Page $url: found a <script> tag with an external src"
            );
        }

        // Ensure we covered at least the 4 standard routes + submit = 5 iterations
        self::assertGreaterThanOrEqual(5, count($routes));
    }

    // Feature: ui-redesign, Property 2: Nav Role and Label Present on Every Page
    // For any page rendered by the application, the HTML SHALL contain a <nav> element
    // with role="navigation" and aria-label="Main navigation".
    // Validates: Requirements 2.1
    public function testNavRoleAndLabelPresentOnEveryPage(): void
    {
        $client = static::createClient();

        foreach ($this->getTestRoutes() as $url) {
            $client->request('GET', $url);
            $html = $client->getResponse()->getContent();

            self::assertStringContainsString(
                'role="navigation"',
                $html,
                "Page $url: missing role=\"navigation\" on nav element"
            );

            self::assertStringContainsString(
                'aria-label="Main navigation"',
                $html,
                "Page $url: missing aria-label=\"Main navigation\" on nav element"
            );
        }
    }

    // Feature: ui-redesign, Property 4: Footer Role Present on Every Page
    // For any page rendered by the application, the HTML SHALL contain a <footer> element
    // with role="contentinfo".
    // Validates: Requirements 3.1
    public function testFooterRolePresentOnEveryPage(): void
    {
        $client = static::createClient();

        foreach ($this->getTestRoutes() as $url) {
            $client->request('GET', $url);
            $html = $client->getResponse()->getContent();

            self::assertStringContainsString(
                '<footer',
                $html,
                "Page $url: missing <footer> element"
            );

            self::assertStringContainsString(
                'role="contentinfo"',
                $html,
                "Page $url: missing role=\"contentinfo\" on footer element"
            );
        }
    }

    // Feature: ui-redesign, Property 5: Viewport Meta Tag Present on Every Page
    // For any page rendered by the application, the <head> SHALL contain
    // <meta name="viewport" content="width=device-width, initial-scale=1">.
    // Validates: Requirements 6.1
    public function testViewportMetaTagPresentOnEveryPage(): void
    {
        $client = static::createClient();

        foreach ($this->getTestRoutes() as $url) {
            $client->request('GET', $url);
            $html = $client->getResponse()->getContent();

            self::assertStringContainsString(
                '<meta name="viewport" content="width=device-width, initial-scale=1">',
                $html,
                "Page $url: missing viewport meta tag"
            );
        }
    }

    // Feature: ui-redesign, Property 6: Main Landmark Present on Every Page
    // For any page rendered by the application, the HTML SHALL contain a <main> element
    // with id="main-content".
    // Validates: Requirements 7.1
    public function testMainLandmarkPresentOnEveryPage(): void
    {
        $client = static::createClient();

        foreach ($this->getTestRoutes() as $url) {
            $client->request('GET', $url);
            $html = $client->getResponse()->getContent();

            self::assertStringContainsString(
                '<main id="main-content"',
                $html,
                "Page $url: missing <main id=\"main-content\"> element"
            );
        }
    }

    // Feature: ui-redesign, Property 7: Skip Link Present on Every Page
    // For any page rendered by the application, the HTML SHALL contain a skip link
    // <a href="#main-content"> that appears before the <nav> element in document order.
    // Validates: Requirements 7.2
    public function testSkipLinkPresentOnEveryPage(): void
    {
        $client = static::createClient();

        foreach ($this->getTestRoutes() as $url) {
            $client->request('GET', $url);
            $html = $client->getResponse()->getContent();

            self::assertStringContainsString(
                'href="#main-content"',
                $html,
                "Page $url: missing skip link with href=\"#main-content\""
            );

            $skipLinkPos = strpos($html, 'href="#main-content"');
            $navPos = strpos($html, '<nav');

            self::assertNotFalse($skipLinkPos, "Page $url: skip link not found");
            self::assertNotFalse($navPos, "Page $url: <nav> element not found");
            self::assertLessThan(
                $navPos,
                $skipLinkPos,
                "Page $url: skip link must appear before <nav> in document order"
            );
        }
    }

    // Feature: ui-redesign, Property 11: Exactly One h1 Per Page
    // For any page rendered by the application, the HTML SHALL contain exactly one <h1> element.
    // Validates: Requirements 7.6
    public function testExactlyOneH1PerPage(): void
    {
        $client = static::createClient();

        foreach ($this->getTestRoutes() as $url) {
            $client->request('GET', $url);
            $html = $client->getResponse()->getContent();

            self::assertSame(
                1,
                substr_count($html, '<h1'),
                "Page $url: expected exactly one <h1> element"
            );
        }
    }

    // Feature: ui-redesign, Property 14: Old Application Name Absent from All Pages
    // For any page rendered by the application, the string "PGP Reply-back" SHALL NOT
    // appear anywhere in the rendered HTML.
    // Validates: Requirements 10.4
    public function testOldApplicationNameAbsentFromAllPages(): void
    {
        $client = static::createClient();

        foreach ($this->getTestRoutes() as $url) {
            $client->request('GET', $url);
            $html = $client->getResponse()->getContent();

            self::assertStringNotContainsString(
                'PGP Reply-back',
                $html,
                "Page $url: old application name \"PGP Reply-back\" must not appear in rendered HTML"
            );
        }
    }

    // Feature: ui-redesign, Property 15: Dark Theme Attribute on html Element
    // For any page rendered by the application, the <html> element SHALL carry
    // the attribute data-bs-theme="dark".
    // Validates: Requirements 13.1
    public function testDarkThemeAttributeOnHtmlElement(): void
    {
        $client = static::createClient();

        foreach ($this->getTestRoutes() as $url) {
            $client->request('GET', $url);
            $html = $client->getResponse()->getContent();

            self::assertStringContainsString(
                'data-bs-theme="dark"',
                $html,
                "Page $url: missing data-bs-theme=\"dark\" on <html> element"
            );
        }
    }
}
