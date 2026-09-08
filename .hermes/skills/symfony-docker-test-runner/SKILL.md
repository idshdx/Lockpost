---
name: symfony-docker-test-runner
description: Fix Symfony tests timing out inside Docker containers.
---

# Symfony Docker Test Runner Troubleshooting

Use when Symfony/PHPUnit tests time out, hang, or fail inside a `docker-compose run` PHP container — especially when the kernel boot itself is slow or fails before any test runs.

## Trigger

- `docker-compose run --rm php ... phpunit` hangs/timeout on every invocation
- Tests run on host but fail/timeout in Docker
- Kernel boot takes 10s+ or hangs indefinitely
- `composer install` fails with `ext-opcache` missing in container
- `catch (Exception)` blocks don't catch expected exceptions
- Test service overrides fail DI type checks or argument name mismatches

## Core Diagnostic Sequence

### 1. Identify the boot bottleneck

```bash
docker-compose run --rm php bash -c 'cd /var/www/app && XDEBUG_MODE=off timeout 20 php scripts/diagnose-boot.php'
```

If boot succeeds with Xdebug off but stalls with it on, Xdebug is the culprit. If boot still fails, check for missing env vars (especially `APP_SECRET`).

### 2. Three common root causes (check in order)

**A. Stale cache from PHP version change**

If the PHP version in the Docker image changed (e.g., 8.3 → 8.4), the compiled container cache is stale.

```bash
docker-compose run --rm php bash -c 'rm -rf /var/www/app/var/cache/test/'
```

Do NOT use `php bin/console cache:clear --env=test` — that boots the kernel, which hangs if `APP_SECRET` is missing.

**B. Xdebug overhead**

Disable it for test runs: `XDEBUG_MODE=off`

**C. Missing `APP_SECRET` in `.env`**

If `.env` lacks `APP_SECRET` but `.env.test` has it, force `.env.test` loading before `Dotenv::bootEnv('.env')` in `tests/bootstrap.php`.

## Fix: Bootstrap env loading for tests (Option C)

Add a `loadEnvFile()` function in `tests/bootstrap.php` that parses `.env.test` before `Dotenv::bootEnv('.env')`. Must strip inline comments and handle quoted values.

## `ext-opcache` composer error

Opcache is a Zend extension; `extension_loaded('opcache')` returns `false` for Zend extensions, causing Composer's false-negative platform check. Fix: remove `"ext-opcache": "*"` from `composer.json`, run `composer update --lock`.

## Namespace catch resolution

In `App\Controller` namespace, `catch (Exception)` catches `App\Controller\Exception` (non-existent). Always use `\Exception` or `\AppException` explicitly.

## DI test service overrides

Test double MUST extend the real class. Configure in `config/packages/test/services.yaml`. YAML argument names must match PHP constructor parameter names exactly. CRLF YAML line endings cause DI compiler errors — write from inside container with Unix LF. Always clear cache after changing service definitions.

## Generating a test PGP key

```bash
docker-compose run --rm php bash -c 'gpg --batch --generate-key << "EOF"
%no-protection
Key-Type: RSA
Key-Length: 2048
Subkey-Type: RSA
Subkey-Length: 2048
Name-Real: Test User
Name-Email: test@example.com
Expire-Date: 0
%commit
EOF'
docker-compose run --rm php bash -c 'gpg --armor --export test@example.com > /var/www/app/config/pgp/test@example.com.pub'
```

## References

- `references/symfony-docker-test-runner-fixes-2026-08-18.md` — session-specific error transcripts and fix recipes.
