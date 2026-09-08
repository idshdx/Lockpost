---
name: docker-php-test-runner
description: "Diagnose and fix PHP Docker container test runner hangs — stale cache, Xdebug slowdown, missing env, and opcache preloading issues. Use when docker-compose run --rm php phpunit times out or hangs at kernel boot."
version: 1.0.0
author: Hermes Agent
license: MIT
platforms: [linux, windows]
metadata:
  hermes:
    tags: [docker, php, testing, debugging, symfony, xdebug, opcache]
    related_skills: [systematic-debugging]
---

# Docker PHP Test Runner

Diagnose and fix hangs/timeouts when running PHPUnit tests inside a PHP Docker container (`docker-compose run --rm php`). This covers the common failure modes that cause kernel boot to stall.

## When to Use

- `docker-compose run --rm php ./vendor/bin/phpunit ...` times out or hangs
- Container starts but tests never execute
- `bin/console cache:clear` itself times out
- Boot diagnostic shows kernel taking 10s+ when it should be ~1s

## Quick Diagnostic Order

Run these in sequence. Each one is a narrow probe — don't chain them all into one command.

### 1. Check container cache freshness

```bash
docker-compose run --rm php bash -c 'cd /var/www/app && ls -lh var/cache/test/App_KernelTestDebugContainer.ser var/cache/test/App_KernelTestDebugContainer.php'
```

If the `.ser` file exists and was modified before the PHP version changed, it's stale. **Direct delete** (not `cache:clear`):

```bash
docker-compose run --rm php bash -c 'cd /var/www/app && rm -rf var/cache/test/*'
```

Why not `cache:clear`? That command boots the kernel to clear the cache — if the kernel can't boot, the clear command itself hangs.

### 2. Check Xdebug impact

Xdebug slows PHP boot significantly. Compare with and without:

```bash
# Without Xdebug (fast)
docker-compose run --rm php bash -c 'cd /var/www/app && XDEBUG_MODE=off timeout 20 php scripts/diagnose-boot.php'

# With Xdebug (slow — may time out)
docker-compose run --rm php bash -c 'cd /var/www/app && timeout 20 php scripts/diagnose-boot.php'
```

If disabling Xdebug drops boot from 15s+ to ~1.4s, Xdebug is the bottleneck. For test runs, always disable it:

```bash
docker-compose run --rm php bash -c 'cd /var/www/app && XDEBUG_MODE=off ./vendor/bin/phpunit ...'
```

Or disable globally in the container by adjusting `docker/php/conf.d/xdebug.ini`.

### 3. Check .env has required vars

The kernel hangs during compilation if `APP_SECRET` (or other required parameters) is missing:

```bash
docker-compose run --rm php bash -c 'cd /var/www/app && cat .env | grep APP_SECRET; cat .env.test | grep APP_SECRET'
```

If missing, copy `.env.example` to `.env` and fill in secrets:

```bash
docker-compose run --rm php bash -c 'cd /var/www/app && cp .env.example .env'
# Then edit .env to set APP_SECRET, PGP_PRIVATE_KEY_PASSPHRASE, etc.
```

**Note**: `tests/bootstrap.php` calls `Dotenv::bootEnv('.env')` BEFORE PHPUnit sets `APP_ENV=test`. So `.env` must have all required vars — `.env.test` is not loaded early enough.

### 4. Run boot-timing diagnostic

Use a PHP script (not bash — bash quoting issues on Windows+MSYS+Docker combos are common) that measures kernel boot time, checks service availability, and reports container health. See `references/boot-timing-script.php` for a ready-to-use template.

## Root Causes and Fixes

| Symptom | Likely Cause | Fix |
|---------|-------------|-----|
| Container hangs during `cache:clear` | Stale `.ser` container file + kernel can't boot to clear it | `rm -rf var/cache/test/*` directly, then let container rebuild on next run |
| Boot takes 15s+ with Xdebug on | Xdebug overhead on every include | Disable Xdebug for test runs: `XDEBUG_MODE=off` |
| Boot fails with "APP_SECRET required" | `.env` missing required env vars | Copy `.env.example` → `.env`, fill in secrets |
| Container file includes OK but boot times out | Container `.ser` was built for different PHP version | Clear `var/cache/test/*`, let it regenerate |
| opcache.preload points to non-existent prod file | Preload config references `App_KernelProdContainer.preload.php` which doesn't exist in test env | Harmless — preload file just doesn't exist, opcache silently skips. But verify it's not causing slowdowns. |

## The .env Loading Order Trap

This is the most subtle failure mode. The sequence inside the container is:

1. `tests/bootstrap.php` runs → calls `Dotenv::bootEnv('.env')`
2. At this point `APP_ENV` is NOT set yet → loads `.env` only (not `.env.test`)
3. If `.env` lacks `APP_SECRET` → container compilation fails/hangs
4. PHPUnit later sets `APP_ENV=test` via `<server>` XML config, but too late

**Fix**: `.env` must be complete. `.env.test` is for overrides that apply AFTER bootstrap, not for primary values.

## Windows + MSYS2 + Docker Path Quirks

When running `docker-compose run --rm php bash -c '...'` from a Windows host via git-bash/MSYS2:

- **Bash heredocs fail** — `<< 'EOF'` delimiters can be cut off by MSYS path mangling. Prefer `bash -c 'inline script'` with single quotes inside, or write the script to a file and run it with `bash script.sh`.
- **`cd` in bash -c can pick up wrong paths** — if `docker-compose.yml` has a `working_dir` that MSYS converts differently, the container may land in an unexpected directory. Verify with `pwd` first.
- **Path conversion** — MSYS2 tries to convert Windows paths to POSIX. When passing absolute Windows paths to native tools inside the container, use forward-slash paths (`C:/Users/...`) or let the container handle relative paths.

## Files in This Skill

- `references/boot-timing-script.php` — A ready-to-use PHP diagnostic script that measures kernel boot time, checks service availability, and reports container health. Copy it into your project's `scripts/` directory and run via `docker-compose run --rm php bash -c 'cd /var/www/app && php scripts/boot-timing.php'`.
