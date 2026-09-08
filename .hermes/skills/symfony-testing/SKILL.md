---
name: symfony-testing
description: "Test Symfony apps using PHPUnit and WebTestCase."
version: 1.0.0
author: Hermes Agent
license: MIT
platforms: [linux, macos, windows]
metadata:
  hermes:
    tags: [symfony, phpunit, webtestcase, php, testing, e2e]
    related_skills: [test-driven-development, bmad-qa-generate-e2e-tests]
---

# Symfony PHPUnit Testing

## Overview

Test Symfony applications using PHPUnit + `WebTestCase`. Covers service overrides in test mode, rate limiter isolation, CSRF token handling, and adding security headers via event listeners.

## When to Use

- Writing E2E/integration tests for Symfony controllers
- Debugging test service overrides that don't get picked up by the compiled container
- Tests failing due to rate limiter state leaking between test methods
- Need to submit forms or POST requests with CSRF protection in tests
- Adding security headers (X-Frame-Options, X-Content-Type-Options, etc.)

## Key Pitfalls & Patterns

### 1. Service Override Location

**Pitfall:** `config/packages/test/services.yaml` is NOT reliably loaded by Symfony's compiled container for service class overrides.

**Fix:** Use `config/services_test.yaml` (project root, standard Symfony convention) for test-only service overrides:

```yaml
# config/services_test.yaml
services:
    App\Service\PgpKeyService:
        class: App\Service\PgpKeyServiceTest
        arguments:
            $testKeyPath: '/var/www/app/config/pgp/test@example.com.pub'
        autowire: false
        autoconfigure: false
```

**Why:** The kernel loads `config/services_test.yaml` as a separate file during test environment boot. Overrides in `config/packages/test/` may be ignored for class replacement.

**Also:** When overriding a service with a test double, override ALL public methods that controllers call. If a controller calls `getPgpKeyResult()` but you only override `getPublicKeyByEmail()`, the real method still runs.

### 2. Rate Limiter Cache Isolation

**Pitfall:** Symfony rate limiter state persists in `cache.rate_limiter` across test methods. Exhausting a limit in one test breaks subsequent tests.

**Fix:** Add `tearDown()` to clear the cache pool:

```php
protected function tearDown(): void
{
    parent::tearDown();
    $container = static::getContainer();
    if ($container->has('cache.rate_limiter')) {
        $container->get('cache.rate_limiter')->clear();
    }
    static::ensureKernelShutdown();
}
```

### 3. CSRF Token Extraction for POST Tests

**Pitfall:** `CsrfTokenManager::getToken()` requires an active session, which isn't available until after the first request.

**Fix:** Extract the CSRF token from a previous GET request's HTML:

```php
$client->request('GET', '/submit/' . $token);
$html = $client->getResponse()->getContent();
preg_match('/data-submit-csrf-token-value="([^"]+)"/', $html, $matches);
$csrfToken = $matches[1];
```

### 4. Adding Security Headers via Event Listener

To add security headers to every response, create a `kernel.response` event listener:

```php
namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\ResponseEvent;

class SecurityHeadersListener
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $response = $event->getResponse();
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
```

Register in `config/services.yaml`:

```yaml
App\EventListener\SecurityHeadersListener:
    tags:
        - { name: kernel.event_listener, event: kernel.response, method: onKernelResponse }
```

### 5. Test Doubles Must Extend the Original Class

When overriding a service with a test double, the double MUST extend the original class. Symfony DI performs type checks, and controllers type-hint the original class:

```php
class PgpKeyServiceTest extends PgpKeyService  // Must extend
{
    // Override ALL methods called by controllers
}
```

Don't call `parent::__construct()` if the parent has heavy dependencies (e.g., GnuPG). Instead, skip it and initialize only what the test double needs.

## Running Tests

```bash
# Full suite
docker compose run --rm php vendor/bin/phpunit --no-coverage

# Filter by test class
docker compose run --rm php vendor/bin/phpunit --filter "SecurityHeadersE2ETest" --no-coverage

# Clear cache + warmup (required after service config changes)
docker compose run --rm php sh -c "rm -rf var/cache/test && php bin/console cache:warmup --env=test"
```
