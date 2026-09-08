# Symfony 7.4 Upgrade: Deployment Error Transcript

## Context

Deploying a new app version that upgraded Symfony from 7.1 to 7.4 on an Oracle Cloud ARM VPS (Ubuntu 24.04) with Docker Compose + PHP 8.3 Alpine containers.

## Error 1: LOCK_DSN env not found

```
The controller for URI "/" is not callable: Environment variable not found: "LOCK_DSN".
Symfony\Component\Lock\Exception\EnvNotFoundException:
Environment variable not found: "LOCK_DSN".
  at vendor/symfony/dependency-injection/EnvVarProcessor.php:221
```

**Root cause:** `symfony/lock` was updated (via `composer update symfony/*`) to a version that no longer auto-detects a backend. The `config/packages/lock.yaml` uses `lock: '%env(LOCK_DSN)%'` which requires the env var to be set.

**Fix:** Add `LOCK_DSN=flock` to `.env`. Use `flock` (file-based, no PHP extension) not `semaphore` (requires `sysvsem` PHP extension not available on Alpine by default).

## Error 2: Semaphore extension required

```
InvalidArgumentException: Semaphore extension (sysvsem) is required.
  at vendor/symfony/lock/Store/SemaphoreStore.php:39
```

**Root cause:** After setting `LOCK_DSN=semaphore`, the SemaphoreStore requires the `sysvsem` PHP extension which is not compiled into the Alpine PHP-FPM image.

**Fix:** Changed `LOCK_DSN` from `semaphore` to `flock` — file-based locking backend, no extension needed.

## Error 3: Fresh `git init` shows all files as diffs

**Root cause:** On the VPS, `git init` was run in `/opt/lockpost` (which had no `.git` directory). After `git fetch origin && git reset --hard origin/main`, the local files were overwritten but git shows everything as "new" since there's no commit history locally.

**Fix:** This is expected behavior for a code-sync-only deployment. The important thing is that the working tree matches `origin/main`. Use `git log --oneline HEAD -3` to verify the code is current.

## Working Deployment Sequence (verified)

```bash
# 1. Pull latest code
cd /opt/lockpost
git fetch origin && git reset --hard origin/main
find docker/ -type f -exec sed -i 's/\r$//' {} +  # fix CRLF

# 2. Update Composer deps (Symfony 7.1 → 7.4)
docker compose -f docker-compose.prod.yml exec php sh -c \
  'composer update symfony/* --with-all-dependencies; composer dump-autoload --optimize'

# 3. Add missing env vars
# LOCK_DSN=flock (add to .env if missing)
# TRUSTED_PROXIES=127.0.0.1,172.18.0.0/16 (add to .env if missing)

# 4. Clear ALL caches (NOT cache:clear alone — stale cache causes class-not-found)
docker compose -f docker-compose.prod.yml exec php sh -c \
  'rm -rf var/cache/prod/* var/cache/test/*'

# 5. Clear + warm prod cache separately
docker compose -f docker-compose.prod.yml exec php sh -c \
  'export APP_ENV=prod APP_DEBUG=0; php bin/console cache:clear; php bin/console cache:warmup; chown -R appuser:appgroup var/'

# 6. Run tests (MUST set APP_ENV=test explicitly — env_file sets it to prod)
docker compose -f docker-compose.prod.yml exec php sh -c \
  'export APP_ENV=test APP_DEBUG=1; php bin/phpunit tests/BootstrapTest.php --no-coverage'

# 7. Restart containers
docker compose -f docker-compose.prod.yml restart php nginx

# 8. Verify
curl -sI http://129.159.7.42/
curl -s http://129.159.7.42/server-key | head -2
```

## Key Insight: env_file overrides phpunit.xml.dist

The `env_file: .env` in docker-compose sets `APP_ENV=prod` at the container level. Symfony's `Dotenv::bootEnv()` reads this, so PHPUnit's `<env name="APP_ENV" value="test"/>` in `phpunit.xml.dist` is ignored. **Must always `export APP_ENV=test APP_DEBUG=1` explicitly** when running tests in a production container environment.
