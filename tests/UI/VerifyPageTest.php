<?php

namespace App\Tests\UI;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the /verify page.
 *
 * Validates: Requirements 9.1, 9.2, 7.5, 7.6
 */
class VerifyPageTest extends WebTestCase
{
    public function testVerifyPageHasExactlyOneSubmitButton(): void
    {
        $client = static::createClient();
        $client->request('GET', '/verify');

        self::assertResponseStatusCodeSame(200);

        $html = $client->getResponse()->getContent();

        // Property 9.1: exactly one submit button inside the form
        self::assertSame(
            1,
            substr_count($html, 'type="submit"'),
            'Expected exactly one type="submit" button on the verify page'
        );
    }

    public function testCopyButtonsHaveAriaLabels(): void
    {
        $client = static::createClient();
        $client->request('GET', '/verify');

        $html = $client->getResponse()->getContent();

        // Find all buttons with clipboard action — icon-only ones must have aria-label,
        // buttons with visible text don't need it.
        preg_match_all('/<button\b([^>]*)data-action="clipboard#copy"([^>]*)>(.*?)<\/button>/is', $html, $matches);

        self::assertNotEmpty($matches[0], 'Expected at least one clipboard copy button on /verify');

        foreach ($matches[0] as $index => $fullButton) {
            $buttonAttrs = $matches[1][$index] . $matches[2][$index];
            $buttonContent = $matches[3][$index];

            // Strip icon tags and check for visible text
            $textOnly = trim(strip_tags(preg_replace('/<i\b[^>]*>.*?<\/i>/is', '', $buttonContent)));
            if ($textOnly !== '') {
                // Has visible text — accessible name provided by text content, no aria-label needed
                continue;
            }

            // Icon-only: must have aria-label
            self::assertMatchesRegularExpression(
                '/aria-label=["\'][^"\']+["\']/i',
                $buttonAttrs,
                "Icon-only copy button is missing aria-label: $fullButton"
            );
        }
    }

    public function testVerifyPageHasH1AsPageHeading(): void
    {
        $client = static::createClient();
        $client->request('GET', '/verify');

        $html = $client->getResponse()->getContent();

        // Property 7.6: primary heading must be <h1>, not <h2>
        self::assertStringContainsString('<h1', $html, 'Expected an <h1> element on /verify');
        self::assertSame(1, substr_count($html, '<h1'), 'Expected exactly one <h1> on /verify');
    }
}
