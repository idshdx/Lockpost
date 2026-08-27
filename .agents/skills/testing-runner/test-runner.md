---
skill: test-runner
domain: testing-runner
title: Running Tests
description: Running the PHPUnit test suite for the Lockpost Symfony PHP application.
---

# Test Runner

**Use when:** Running the PHPUnit test suite for the Lockpost project.

## Prerequisites
- Docker container built: `docker compose build`
- Dependencies installed: `docker compose exec php composer install --no-scripts`
- PGP keys generated (for PgpSigningService and Controller tests): see `dev-environment/local-dev.md`

## Environment Variables for Test Execution
Always pass these env vars when using `docker compose run`:
```
APP_ENV=test
APP_SECRET=test-secret-32-chars-long!!
PGP_PRIVATE_KEY_PASSPHRASE=your-secure-passphrase
APP_MAIL_FROM=noreply@lockpost.local
MAILER_DSN=smtp://mailhog:1025
GNUPGHOME=/var/www/app/config/pgp/key-config
```

## Commands

### Full Test Suite
```bash
# Method 1: Run against the started container (simplest — env vars loaded from .env)
docker compose exec php php bin/phpunit --no-coverage

# Method 2: Fresh container (no service start required)
docker compose run --rm --no-deps \
  -e APP_ENV=test \
  -e APP_SECRET=test-secret-32-chars-long!! \
  -e PGP_PRIVATE_KEY_PASSPHRASE=your-secure-passphrase \
  -e APP_MAIL_FROM=noreply@lockpost.local \
  -e MAILER_DSN=smtp://mailhog:1025 \
  -e GNUPGHOME=/var/www/app/config/pgp/key-config \
  php php bin/phpunit --no-coverage
```
> **Note:** Method 1 works because the test environment loads `.env` automatically. Use Method 2 only if you need a fresh container for isolation.

### Individual Test Files
```bash
# Token link tests (no PGP/GPG needed — fastest to run)
docker compose exec php php bin/phpunit tests/Service/TokenLinkServiceTest.php --no-coverage

# PGP signing tests (requires GPG keys)
docker compose exec php php bin/phpunit tests/Service/PgpSigningServiceTest.php --no-coverage

# PGP key service tests (uses mock HTTP client, no network, no GPG)
docker compose exec php php bin/phpunit tests/Service/PgpKeyServiceTest.php --no-coverage

# Controller tests (requires full kernel boot + GPG keys)
docker compose exec php php bin/phpunit tests/Controller/DefaultControllerTest.php --no-coverage
```

## Known Issues
- **ext-opcache platform check**: `composer.json` now has `"platform-check": false` and `"ext-opcache": "8.3"` in `config.platform`. No `--ignore-platform-req` flag is needed anymore.
- **Missing `symfony/lock`**: Already added to `composer.json` `require` section.
- **Container hangs on `docker compose run`**: Use `--no-deps` flag to avoid starting dependent services. The entrypoint starts PHP-FPM as a daemon; always pass an explicit command.
- **chown path issues**: Use `docker compose exec php bash -c "chown -R www-data:www-data /var/www/app/var/ /var/www/app/config/pgp/"` (not `docker exec php` without `bash -c`).
- **Full suite timeout**: If tests hang, run test files individually to isolate the problem. The UI tests may time out if GPG keys aren't generated yet.
- **Git safe.directory warning**: When running composer/phpunit as www-data in the container, fix with: `docker compose exec php bash -c "git config --global --add safe.directory /var/www/app"`
