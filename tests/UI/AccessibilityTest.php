<?php

namespace App\Tests\UI;

use App\Service\TokenLinkService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for accessibility properties from the ui-redesign spec.
 *
 * Properties covered:
 *   P8  — Every Form Has an Accessible Name
 *   P9  — Every Input and Textarea Has an Associated Label
 *   P10 — Icon-Only Interactive Elements Have Accessible Names
 *   P12 — Flash Messages Include role="alert"
 */
class AccessibilityTest extends WebTestCase
{
    /**
     * Generates a valid /submit/{token} URL using TokenLinkService from the container.
     */
    private function getSubmitUrl(): string
    {
        $token = static::getContainer()->get(TokenLinkService::class)->generateLink('test@example.com');
        return '/submit/' . $token;
    }

    // Feature: ui-redesign, Property 8: Every Form Has an Accessible Name
    public function testEveryFormHasAnAccessibleName(): void
    {
        $client = static::createClient();

        $urls = ['/', '/verify', $this->getSubmitUrl()];

        foreach ($urls as $url) {
            $client->request('GET', $url);
            $html = $client->getResponse()->getContent();

            // Find all opening <form tags
            preg_match_all('/<form\b[^>]*>/i', $html, $matches);

            self::assertNotEmpty(
                $matches[0],
                "Page $url: expected at least one <form> element"
            );

            foreach ($matches[0] as $formTag) {
                $hasAccessibleName = (
                    stripos($formTag, 'aria-labelledby=') !== false ||
                    stripos($formTag, 'aria-label=') !== false
                );

                self::assertTrue(
                    $hasAccessibleName,
                    "Page $url: found a <form> tag without aria-labelledby or aria-label: $formTag"
                );
            }
        }
    }

    // Feature: ui-redesign, Property 9: Every Input and Textarea Has an Associated Label
    public function testEveryInputAndTextareaHasAnAssociatedLabel(): void
    {
        $client = static::createClient();

        $urls = ['/', '/verify', $this->getSubmitUrl()];

        foreach ($urls as $url) {
            $client->request('GET', $url);
            $html = $client->getResponse()->getContent();

            // Extract all input/textarea elements with an id (skip type="hidden")
            preg_match_all('/<(?:input|textarea)\b[^>]+id="([^"]+)"[^>]*>/i', $html, $matches);

            foreach ($matches[0] as $index => $elementTag) {
                // Skip hidden inputs — they don't need visible labels
                if (preg_match('/type=["\']hidden["\']/i', $elementTag)) {
                    continue;
                }

                $id = $matches[1][$index];

                self::assertStringContainsString(
                    'for="' . $id . '"',
                    $html,
                    "Page $url: no <label for=\"$id\"> found for input/textarea with id=\"$id\""
                );
            }
        }
    }

    // Feature: ui-redesign, Property 10: Icon-Only Interactive Elements Have Accessible Names
    public function testIconOnlyInteractiveElementsHaveAccessibleNames(): void
    {
        $client = static::createClient();

        // /verify has 3 icon-only copy buttons (no visible text, only <i class="bi ...">)
        $client->request('GET', '/verify');
        $html = $client->getResponse()->getContent();

        // Find all <button ...>...</button> blocks
        preg_match_all('/<button\b([^>]*)>(.*?)<\/button>/is', $html, $matches);

        foreach ($matches[0] as $index => $buttonHtml) {
            $buttonAttrs = $matches[1][$index];
            $buttonContent = $matches[2][$index];

            // Check if this button contains a Bootstrap icon
            if (stripos($buttonContent, '<i class="bi') === false) {
                continue;
            }

            // Strip the <i> tag and whitespace to check for visible text
            $contentWithoutIcon = preg_replace('/<i\b[^>]*>.*?<\/i>/is', '', $buttonContent);
            $visibleText = trim(strip_tags($contentWithoutIcon));

            if ($visibleText !== '') {
                // Button has visible text alongside the icon — accessible name is provided by text
                continue;
            }

            // Icon-only button: must have aria-label on the <button> tag itself
            self::assertMatchesRegularExpression(
                '/aria-label=["\'][^"\']+["\']/i',
                $buttonAttrs,
                "Page /verify: icon-only button is missing aria-label: $buttonHtml"
            );
        }
    }

    // Feature: ui-redesign, Property 12: Flash Messages Include role="alert"
    public function testFlashMessagesIncludeRoleAlert(): void
    {
        $client = static::createClient();

        // GET the verify page to obtain a valid CSRF token
        $crawler = $client->request('GET', '/verify');

        // Submit the form with invalid data to trigger a flash message.
        // The verify form POSTs to /verify/signature, which adds a flash and redirects to /verify.
        // We need to find the actual form (which posts to /verify/signature).
        $form = $crawler->filter('form')->form();
        // Submit with empty fields — NotBlank constraints will fail, form is invalid,
        // controller adds flash 'danger' and redirects to app_verify.
        $form['verify_signature_form[message]'] = '';
        $form['verify_signature_form[signature]'] = '';
        $form['verify_signature_form[public_key]'] = '';

        $client->submit($form);

        // Follow the redirect to /verify where the flash is rendered
        $client->followRedirect();

        $html = $client->getResponse()->getContent();

        self::assertStringContainsString(
            'role="alert"',
            $html,
            'Flash message container must include role="alert"'
        );
    }
}
