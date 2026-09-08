---
name: symfony-deployment-audit
description: Audit Symfony Docker deploy config for security and drift.
---

# Symfony Deployment Audit

Systematic review of code, security, Docker, NGINX, shell scripts, and deployment docs for Symfony/PHP apps. Produces a prioritized findings report and can patch high-risk issues directly.

## Trigger

User asks for any of:
- "review this project for technical design / security / issues"
- "review deployment.md / docker-compose / Dockerfile"
- "audit this Symfony app"
- "production readiness review"
- Direct request to create report in `docs/reviews/`

## Workflow

1. Inventory the project: `composer.json`, Dockerfiles, `docker-compose*.yml`, NGINX config, shell scripts, `config/packages/*`, `.env*`
2. Review source for security/architecture: services, controllers, token/crypto handling, error exposure
3. Review Docker/ops: base image freshness, non-root user, healthchecks, network mode, bind mounts, permissions
4. Review deployment docs against actual repo state: env filenames, commands, paths, prerequisites
5. Write findings to `docs/reviews/technical-review.md` if it does not exist
6. Write deployment-specific notes to `docs/reviews/deployment-audit-<date>.md`
7. Patch only if user explicitly approves; prefer minimal, targeted changes

## Common Pitfalls to Check

### NGINX / PHP-FPM
- `APPLICATION_ENV` vs `APP_ENV` FastCGI param
- `client_max_body_size` set too high
- `/status`, `/ping` exposed to public without restriction
- Missing HTTPS/TLS guidance

### Docker
- Production image based on outdated base tags
- Xdebug or dev tools compiled into prod image
- Missing `healthcheck` on dependent services
- `network_mode: host` without documented bridge alternative
- Bind mounts missing `:Z`/`:z` where AppArmor/SELinux may block access
- **Alpine Linux PHP-FPM error_log fails as non-root** — the official `php:8.x-fpm-alpine` image's `docker.conf` sets `error_log = /proc/self/fd/2` and `access.log = /proc/self/fd/2`. When the container runs as a non-root user (e.g., `appuser` via `su-exec`), opening `/proc/self/fd/2` fails with `Permission denied (13)` and PHP-FPM crashes with exit code 78. Fix: `sed -i 's|/proc/self/fd/2|/var/www/app/var/log/php-fpm.log|g' /usr/local/etc/php-fpm.d/docker.conf` to redirect logs to a writable file path, and ensure that path is created with correct ownership in the entrypoint script.
- **`docker compose run` entrypoint hangs** — entrypoint scripts that `chown -R` mounted volumes can hang indefinitely. Use `docker compose run --rm --no-deps <service> <command>` to bypass, or override `--entrypoint=""`.
- **`/bin/bash` not found in Alpine containers** — `php:*-fpm-alpine` images don't include bash. Entrypoint scripts using `#!/bin/bash` fail with `exec: no such file or directory`. Always use `#!/bin/sh` in Alpine-based images.
- **`su-exec` must be explicitly installed in Alpine** — Alpine doesn't include `su-exec` in the base image. Add `apk add su-exec` to the runtime stage, or use `su -s /bin/sh -c "..." appuser` as a fallback.
- **Missing `working_dir` in docker-compose** — without `working_dir: /var/www/app`, `docker-compose run` lands in the image's default WORKDIR (often `/var/www/html`), causing "composer.json not found" errors.
|**Missing `symfony/lock` when using `symfony/rate-limiter`** — the `fixed_window` policy requires `symfony/lock` to be declared as a direct dependency in `composer.json` (it's not auto-installed even as a transitive dep).
|**CRLF line endings cause Docker entrypoint crashes** — files cloned/transferred from Windows hosts have CRLF terminators. An entrypoint like `#!/bin/bash\n` is interpreted as a path containing a carriage-return, producing `exec /usr/local/bin/entrypoint.sh: no such file or directory`. Always `sed -i 's/
$//'` entrypoint scripts, shell scripts, and docker-compose files before building. This is a silent failure: the container restarts with exit code 255 in a crash loop. If working server-first, run `find docker/ -type f -exec sed -i 's/
$//' {} +` as a single remediation step after any Windows-origin file transfer.

### Post-Deployment: Server-First Deployment Workflow

The user prefers working on the server first, testing, then syncing changes to the local repo. Use this pattern for all deployments:

1. **Pull new code on server:** `git init && git remote add origin <url> && git fetch origin && git reset --hard origin/main`
2. **Fix line endings:** `find docker/ -type f -exec sed -i 's/
$//'`
3. **Update Composer deps if Symfony version changed:** `docker compose exec php sh -c 'composer update symfony/* --with-all-dependencies'`
4. **Fix env vars for new version:** Check for new required env vars (e.g., `LOCK_DSN`, `TRUSTED_PROXIES`, `APP_TOKEN_STATEFUL`). Scan `config/services.yaml` for `%env(...)%` patterns.
5. **Clear all caches:** `rm -rf var/cache/*` before running any console commands
6. **Warm caches separately:** `php bin/console cache:clear` then `php bin/console cache:warmup`
7. **Run tests:** `export APP_ENV=test APP_DEBUG=1; php bin/phpunit tests/BootstrapTest.php --no-coverage`
8. **Restart containers:** `docker compose restart php nginx` (cron doesn't need rebuild)
9. **Sync changes back to local repo** after confirming everything works

||**Symfony 7.4 `csrf.yaml` config requires `token_id` and `stateless_token_ids`** — these options are only recognized in Symfony 7.4+. If the container still has 7.1 packages (stale `composer.lock`), the config throws `Unrecognized option "token_id" under "framework.form.csrf_protection"`. Fix: run `composer update symfony/* --with-all-dependencies` inside the container, then `composer dump-autoload --optimize`.

||**Symfony Lock component `LOCK_DSN` env var missing after composer update** — when `symfony/lock` is updated to a version that no longer auto-detects a backend, the container throws `EnvNotFoundException: Environment variable not found: "LOCK_DSN"`. The `lock.yaml` config uses `lock: '%env(LOCK_DSN)%'`. Fix: add `LOCK_DSN=flock` to `.env` (file-based locking, no PHP extension required). The `semaphore` backend requires the `sysvsem` PHP extension which is not installed in Alpine containers by default — use `flock` instead.

||**Symfony cache must be fully cleared before testing after code updates** — stale cache in `var/cache/test/` and `var/cache/prod/` causes "class not found" or config errors. Always `rm -rf var/cache/*` before running `cache:clear`, `cache:warmup`, or `phpunit`.

|**Symfony 7.4 `TRUSTED_PROXIES` env var for proxy header forwarding** — when using `network_mode: host`, Symfony needs `trusted_proxies` configured to correctly handle forwarded headers. Add `TRUSTED_PROXIES=127.0.0.1,172.18.0.0/16` to `.env` and configure `config/packages/prod/framework.yaml` with `framework.trusted_proxies: '%env(TRUSTED_PROXIES)%'`.

|**PHP source files with double-escaped backslashes break class loading** — when a GitHub PR commit accidentally double-escapes backslashes in `use` statements (e.g., `use Monolog\\LogRecord;` instead of `use Monolog\LogRecord;`), PHP throws `ParseError: syntax error, unexpected fully qualified name "\\LogRecord"`. The file looks correct in a text editor showing `\LogRecord` but the raw bytes contain `\\`. Check with `od -c file.php | grep 'use Monolog'` — correct files show a single `\`. Fix: rewrite the file ensuring proper single backslashes, or use Python byte-level replacement.

|**New Symfony features require new env vars without backward compat** — when upgrading to a new Symfony version, new optional features (e.g., stateful link mode, security headers listener) may introduce env var dependencies like `APP_TOKEN_STATEFUL` that cause `EnvNotFoundException` at runtime. Check `config/services.yaml` and `src/` for `%env(...)%` patterns before deploying.

### Networking / Firewall
|**Oracle Cloud Security List blocks inbound 80/443 by default** — the default Security List only allows TCP 22. Must add explicit ingress rules (Source 0.0.0.0/0, TCP, ports 80 and 443) in the OCI Console. Local ufw rules alone are insufficient.
|**iptables REJECT rule before ufw chain** — some VPS images ship a blanket `REJECT` rule in INPUT before the ufw rule chain, silently dropping all non-SSH traffic. Diagnose with `sudo iptables -L INPUT -n --line-numbers`; remove the offending rule with `sudo iptables -D INPUT <N>`.
|**`network_mode: host` means NGINX binds directly to host port 80** — no port mapping in compose; the VNIC IP == host IP. Confirm with `docker inspect --format '{{.HostConfig.NetworkMode}}'`.

### Symfony Testing on Production Deploy
|**Container `env_file` overrides PHPUnit env vars** — when `docker-compose.prod.yml` uses `env_file: .env` (setting `APP_ENV=prod`), running PHPUnit fails because `phpunit.xml.dist`'s `<env name="APP_ENV" value="test"/>` is set via `putenv()` but Symfony's `Dotenv::bootEnv()` respects the container-level env var. Fix: explicitly `export APP_ENV=test APP_DEBUG=1` before running phpunit inside the container.
|**Missing importmap vendor assets cause 500 on route rendering** — `importmap.php` entries like `@hotwired/stimulus` and `openpgp` must be installed via `importmap:install` and `importmap:require openpgp`. The homepage returns 500 with `"vendor asset is missing"` if these are not downloaded.

### Env / Config
- `.env.prod` vs `.env` naming mismatch between compose, docs, and example files
- Hardcoded fingerprints/key IDs in `gpg.yaml`
- Missing required env vars in `.env.test`
- **`ext-zend-opcache` vs `ext-opcache`** — Composer platform package name should be `ext-opcache` (standard since PHP 5.5). `ext-zend-opcache` is the legacy PECL-only name and causes `composer check-platform-reqs` to fail.

### Shell Scripts
- BSD `stat -f "%OLp"` used on Ubuntu/Debian targets
- `docker exec -it` in non-interactive scripts/CI
- `%no-protection` / blank passphrase encouraged in prod
- Missing Linux-compatible permission checks
- **`composer install` fails when lock file is stale after composer.json edits** — after adding a dependency to `composer.json`, run `composer update <package> --no-scripts` to update the lock file before `composer install`. The `--ignore-platform-req=ext-opcache` flag may be needed in containers where the platform check is unreliable.

### GnuPG / Keys
- Keys generated without passphrase in production paths
- Permission/ownership only enforced in Docker entrypoint, not documented for host deploys
- Server keyring reused for arbitrary user public keys during verification

### Token / Crypto
- AES-256-CBC when GCM is available
- `hash('sha256', secret)` without salt/stretching
- Raw exception details returned to clients in JSON error responses
|**Symfony HttpClient `stream()` throws `ClientException` for 4xx during iteration** — when using `httpClient->stream()` with `MockHttpClient`, 4xx/5xx responses throw during `stream()` iteration before `getContent(false)` is reached. Fix: set `http_errors => false` in request options, and call `$response->cancel()` in a `finally` block on all responses to prevent `MockResponse::__destruct` from throwing.

### Token Leakage Through Logs
|**NGINX access logs capture full request URIs including tokens** — `/submit/{token}` paths are written to access logs when `access_log` is enabled. Mitigations: (1) set `access_log off` at the server block level, (2) add explicit `location ~ ^/submit/` and `location ~ ^/message/submit` blocks with `access_log off` as defense-in-depth, (3) add `Referrer-Policy: no-referrer` (dev) or `Referrer-Policy: strict-origin-when-cross-origin` (prod) to prevent token paths leaking via Referer headers.
|**PHP `gnupg` extension ignores constructor `homedir` option when `GNUPGHOME` env var is set** — the extension reads `GNUPGHOME` from the environment per-operation. Workarounds: (1) in production, don't set `GNUPGHOME` globally — use a save/restore `putenv()` pattern around signing and verification, (2) use `gpg --homedir` CLI for all verification operations, (3) always restore the original `GNUPGHOME` in a `finally` block.
|**Monolog debug level logs tokens and email addresses** — `level: debug` in `monolog.yaml` captures request data including tokens in the `dev` environment. Fix: set `level: warning` in `when@prod` for production, and add a `TokenScrubbingProcessor` that redacts token-like strings and email addresses from all log messages and context.
|**Exception objects in log context leak stack traces** — passing `['exception' => $e]` as monolog context causes Symfony to serialize the full stack trace, which may contain token values from request parameters. Fix: log only the exception class name or a sanitized message string.

## Output Rules

- Be concise. User prefers direct findings + exact fixes over narrative.
- Group by severity: High / Medium / Low.
- If patching, make the smallest possible change and explain why in one line.
- Do not implement beyond docs unless explicitly told to.
- **Server-first workflow preference**: User explicitly prefers testing changes on the remote VPS first (via SSH), then syncing to the local repo. When the user asks "is there a reason why you don't work from the start on the server", always favor server-side testing and sync back to local afterward.

## References

- `references/deployment-findings-2026-08-15.md` — canonical checklist from Lockpost audit
- `references/cross-platform-symfony-skill-audit-2026-08-16.md` — concrete replacements for Windows-biased wording in local-dev, SSH, test-runner, and port-conflict skills
- `references/vps-deployment-checklist-2026-08-16.md` — full VPS deployment runbook for Symfony/NGINX/PHP-FPM on Oracle Cloud (Ubuntu), covering CRLF fixes, Security List ingress, iptables REJECT workaround, PGP key generation, Postfix setup, and Symfony test env var override pattern.
- `references/symfony-7.4-upgrade-deployment-errors-2026-08-16.md` — specific error transcript and fixes for deploying a Symfony 7.1→7.4 upgrade on the VPS (LOCK_DSN env, sysvsem extension, cache clearing, env_file override of phpunit.xml.dist).
