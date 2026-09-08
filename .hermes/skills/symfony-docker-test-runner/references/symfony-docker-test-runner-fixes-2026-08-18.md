# Symfony Docker Test Runner Fixes — 2026-08-18 Session

Session: sym-pgp-ony Docker test runner fix (Mihai's Lockpost project)

## Error transcripts and fixes

### 1. `composer install` — `ext-opcache` missing

```
Root composer.json requires PHP extension ext-opcache * but it is missing from your system.
Your lock file does not contain a compatible set of packages. Please run composer update.
```

**Root cause:** Opcache is a Zend extension loaded via `zend_extension=opcache`. PHP's `extension_loaded('opcache')` returns `false` for Zend extensions. Composer uses `extension_loaded()` for platform checks, producing a false negative.

**Fix:** 
1. Remove `"ext-opcache": "*"` from `composer.json`
2. `composer update --lock --no-interaction` inside container
3. `composer install --no-interaction` inside container

### 2. Kernel boot timeout on every test invocation

**Three compounding causes:**
- Stale cache: `var/cache/test/` was built for PHP 8.3, container runs PHP 8.4.24
- Xdebug: boots in ~15s+ with Xdebug, ~1.4s with `XDEBUG_MODE=off`
- Missing `APP_SECRET` in `.env`: `.env` only contained `APP_TOKEN_TTL`. Kernel compilation requires `%env(APP_SECRET)%` from `framework.yaml`.

**Diagnosis sequence that worked:**
```bash
# Boot diagnostic
docker-compose run --rm php bash -c 'cd /var/www/app && XDEBUG_MODE=off timeout 20 php scripts/diagnose-boot.php'
# Result: boot succeeds in 1.429s with Xdebug off
```

### 3. `catch (Exception)` not catching `\JsonException`

**Symptom:** `testSubmitMessageReturns400ForInvalidJson` returned 500 instead of 400.

**Root cause:** In `App\Controller` namespace, bare `Exception` resolves to `App\Controller\Exception` (non-existent). `\JsonException` (global) escapes. Also, `MessageController::submitMessage()` used undefined `$data` variable causing PHP `Error` which bypasses `catch (Exception)`.

**Fixes:**
- `MessageController.php` line 95: `$dto = null; $validationError = $this->validateAndResolveSubmission($request, $dto, $validator)`
- `MessageController.php` line 101: `$this->sendEncryptedMessageEmail($dto, $dto->getRecipientEmail())`
- All `catch (Exception)` → `catch (\Exception)` in MessageController, LinkController, VerificationController

### 4. DI test service override failure — type mismatch

```
TypeError: MessageController::__construct(): Argument #3 ($pgpKeyService) must be of type App\Service\PgpKeyService, App\Service\PgpKeyServiceTest given
```

**Root cause:** `PgpKeyServiceTest` did NOT extend `PgpKeyService`. When injected into `MessageController` (which type-hints `PgpKeyService`), Symfony's DI container throws a TypeError.

**Fix:** `PgpKeyServiceTest extends PgpKeyService` — only override `getPublicKeyByEmail()`.

### 5. DI test service override failure — argument name mismatch

```
Invalid service "App\Service\PgpKeyService": method "App\Service\PgpKeyServiceTest::__construct()" has no argument named "$keyPath". Check your service definition.
```

**Root cause:** YAML specified `$keyPath` but the PHP class constructor parameter was named `$testKeyPath`.

**Fix:** Make YAML argument name match PHP constructor parameter name exactly.

### 6. DI test service override failure — YAML CRLF line endings

**Symptom:** Same as #5 even after fixing argument name.

**Root cause:** `config/packages/test/services.yaml` was written from Windows host with `\r\n` line endings. Symfony DI compiler rejects CRLF YAML.

**Fix:** Write YAML from inside the container with Unix LF:
```bash
docker-compose run --rm php bash -c 'cat > /var/www/app/config/packages/test/services.yaml << "EOF"
services:
    App\Service\PgpKeyService:
        class: App\Service\PgpKeyServiceTest
        arguments:
            $testKeyPath: "%kernel.project_dir%/config/pgp/test@example.com.pub"
EOF'
```

### 7. `config/services_test.yaml` vs `config/packages/test/services.yaml`

- `config/packages/test/services.yaml` — loaded automatically by Symfony when `APP_ENV=test`
- `config/services_test.yaml` — NOT auto-loaded; only used if explicitly imported

Having both caused confusion. The correct location is `config/packages/test/services.yaml`.

### 8. `config/services_test.yaml` had Windows permissions (root-owned, 777)

The file was created inside the container as root with `rwxrwxrwx` permissions. Combined with CRLF line endings, it caused persistent DI compiler errors. Deleting it and using only `config/packages/test/services.yaml` resolved the issue.

### 9. Verifying TokenLinkService works

Diagnostic script `tmp-test-token.php` that manually loads `.env.test` and tests token generation:

```php
// Loaded env vars: APP_SECRET=test-secret-32-characters-change-me, APP_TOKEN_TTL=3600
// Generated token for test@example.com successfully
// Validated token back to test@example.com successfully
```

This confirmed the bootstrap/env loading was correct; the remaining failures were DI/cache issues.

## Files created/modified this session

- `src/Service/PgpKeyServiceTest.php` — test double extending PgpKeyService
- `config/packages/test/services.yaml` — DI override (Unix LF, correct arg name)
- `config/packages/test/framework.yaml` — existed pre-session (rate limiter overrides)
- `tmp-test-token.php` — diagnostic script (deleted after use)
- `config/services_test.yaml` — created then deleted (wrong location)
