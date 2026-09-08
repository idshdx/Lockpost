# Service Override Pitfall — Symfony Test Mode

## Problem

When overriding a Symfony service for tests (e.g., replacing `PgpKeyService` with `PgpKeyServiceTest`), placing the override in `config/packages/test/services.yaml` does NOT work — the compiled container ignores it.

## Symptoms

- Compiled container still shows the original class
- Tests that depend on the mock still call the real service
- `grep 'PgpKeyService' var/cache/test/App_KernelTestDebugContainer.xml` shows original class

## Root Cause

Symfony's kernel does not reliably pick up service overrides from `config/packages/test/services.yaml` for class replacement. The standard location is `config/services_test.yaml` at the project root.

## Solution

Create `config/services_test.yaml` with the override:

```yaml
services:
    App\Service\PgpKeyService:
        class: App\Service\PgpKeyServiceTest
        arguments:
            $testKeyPath: '/var/www/app/config/pgp/test@example.com.pub'
        autowire: false
        autoconfigure: false
```

**Important:** When overriding, set `autowire: false` and `autoconfigure: false` to prevent the container from trying to autowire constructor arguments (especially string arguments).

## Debugging Steps

1. Check compiled container: `grep 'PgpKeyService' var/cache/test/App_KernelTestDebugContainer.xml`
2. Look for `<service id="App\Service\PgpKeyService" class="...">` — class should be the test double
3. If class is still original, the override file isn't being loaded
4. Clear cache + warmup: `rm -rf var/cache/test && php bin/console cache:warmup --env=test`

## Also: Override ALL Controller-Called Methods

If a controller calls `getPgpKeyResult()` but you only override `getPublicKeyByEmail()`, the real method still runs. The test double must extend the original class and override every method the controller invokes.
