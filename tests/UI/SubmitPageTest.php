<?php

namespace App\Tests\UI;

use App\Service\TokenLinkService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the /submit/{token} page.
 *
 * Validates: Requirements 8.5, 14.2
 */
class SubmitPageTest extends WebTestCase
{
    private function getSubmitUrl(): string
    {
        $token = static::getContainer()->get(TokenLinkService::class)->generateLink('test@example.com');
        return '/submit/' . $token;
    }

    public function testSubmitPageContainsNoscriptElement(): void
    {
        $client = static::createClient();
        $client->request('GET', $this->getSubmitUrl());

        self::assertResponseStatusCodeSame(200);

        $html = $client->getResponse()->getContent();

        self::assertStringContainsString(
            '<noscript>',
            $html,
            'Submit page must contain a <noscript> element for JS-disabled users'
        );
    }

    public function testFeedbackDivExistsAndIsHiddenByDefault(): void
    {
        $client = static::createClient();
        $client->request('GET', $this->getSubmitUrl());

        $html = $client->getResponse()->getContent();

        // The feedback div must exist and carry d-none (hidden by default)
        self::assertStringContainsString(
            'data-submit-target="feedback"',
            $html,
            'Submit page must contain a feedback target div'
        );

        self::assertMatchesRegularExpression(
            '/class="[^"]*d-none[^"]*"[^>]*data-submit-target="feedback"|data-submit-target="feedback"[^>]*class="[^"]*d-none[^"]*"/i',
            $html,
            'Feedback div must have d-none class by default'
        );
    }

    public function testFormHasDataControllerSubmitAttribute(): void
    {
        $client = static::createClient();
        $client->request('GET', $this->getSubmitUrl());

        $html = $client->getResponse()->getContent();

        self::assertStringContainsString(
            'data-controller="submit"',
            $html,
            'Submit page form must have data-controller="submit" attribute'
        );
    }

    public function testPublicKeyRenderedIntoDataAttribute(): void
    {
        $client = static::createClient();
        $client->request('GET', $this->getSubmitUrl());

        $html = $client->getResponse()->getContent();

        // The public key must be in data-submit-public-key-value, not a bare JS variable
        self::assertStringContainsString(
            'data-submit-public-key-value=',
            $html,
            'Public key must be rendered into data-submit-public-key-value attribute'
        );

        // Ensure it's NOT exposed as a bare JS variable (old pattern)
        self::assertStringNotContainsString(
            'var publicKey',
            $html,
            'Public key must not be exposed as a bare JS variable'
        );
    }
}
