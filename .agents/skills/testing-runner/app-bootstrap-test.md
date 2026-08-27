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

- Containers built and started: `docker compose up --build -d`
- PHP dependencies installed: `docker compose exec php composer install --no-scripts`
- PGP keys generated if you also want to validate signing-related bundles: `docker compose exec php bash /var/www/app/scripts/init-pgp.sh --with-passphrase your-secure-passphrase`
- File permissions fixed: `docker compose exec php bash -c "chown -R www-data:www-data /var/www/app/var/ /var/www/app/config/pgp/"`
- Git safe directory configured: `docker compose exec php bash -c "git config --global --add safe.directory /var/www/app"`

## Commands

```bash
docker compose exec php php bin/phpunit tests/BootstrapTest.php --no-coverage
```

The test environment automatically loads `.env`, so no extra env vars are needed when using `docker compose exec`.

## Notes

- `tests/BootstrapTest.php` is the project bootstrap smoke test.
- If this test fails, the app is not ready for broader test execution or local use.
- `--no-coverage` keeps this check fast; coverage is not the goal here.
