---
name: app-bootstrap-test
description: Verify the Lockpost Symfony application can bootstrap successfully by running its bootstrap smoke tests.
---

# App Bootstrap Test

**Use when:** Checking whether the application boots and basic core services/route are available. This is a smoke test, not a full functional verification.

## What it checks

- Symfony kernel boots in the `test` environment.
- Core services (`router`, `request_stack`, `event_dispatcher`) are available in the container.
- The default route responds successfully.

## Prerequisites

- Containers built: `docker compose build`
- PHP dependencies installed: `docker compose exec php composer install --no-scripts`
- PGP keys generated if you also want to validate signing-related bundles: `docker compose exec php bash /var/www/app/scripts/init-pgp.sh`
- File permissions fixed: `docker compose exec php bash -c "chown -R www-data:www-data /var/www/app/var/ /var/www/app/config/pgp/"`

## Commands

```bash
# Method 1: Run against the started container
docker compose exec php php bin/phpunit tests/BootstrapTest.php --no-coverage

# Method 2: Fresh container with explicit test env
docker compose run --rm --no-deps \
  -e APP_ENV=test \
  -e APP_SECRET=test-secret-32-chars-long!! \
  -e PGP_PRIVATE_KEY_PASSPHRASE=your-secure-passphrase \
  -e APP_MAIL_FROM=noreply@lockpost.local \
  -e MAILER_DSN=smtp://mailhog:1025 \
  -e GNUPGHOME=/var/www/app/config/pgp/key-config \
  php php bin/phpunit tests/BootstrapTest.php --no-coverage
```

## Notes

- `tests/BootstrapTest.php` is the project bootstrap smoke test.
- If this test fails, the app is not ready for broader test execution or local use.
- `--no-coverage` keeps this check fast; coverage is not the goal here.
- `--no-deps` avoids starting dependent services during `docker compose run`.
