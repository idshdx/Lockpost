# Debugging Notes — sym-pgp-ony (Aug 16, 2026)

## Full Test Suite Hang — Root Cause: Missing `symfony/lock`

**Symptom**: `bin/phpunit --no-coverage` hangs after printing `E` (error in BootstrapTest).
**Exit code**: 124 (timeout), no useful output.

**Root cause**: `symfony/rate-limiter` with `fixed_window` policy requires `symfony/lock`. The package was in `composer.json` as a transitive dependency but NOT explicitly declared. After a `composer.lock` refresh (which happened when `symfony/rate-limiter` was added), `symfony/lock` was no longer guaranteed to be installed.

**Error message** (revealed by booting kernel manually):
```
LogicException: Rate limiter "submit_ip" requires the Lock component to be installed.
Try running "composer require symfony/lock".
```

**Fix applied**:
1. Added `"symfony/lock": "7.1.*"` to `composer.json` require section
2. Ran `composer update symfony/lock --no-scripts --ignore-platform-req=ext-opcache`
3. Rebuild Docker image: `docker compose build php`

**Verification**: Kernel boots successfully after the fix.

## Docker `docker compose run` Hang

**Symptom**: `docker compose run --rm php php bin/phpunit` hangs indefinitely.
**Root cause**: The container's `entrypoint.sh` runs, then `exec "$@"` starts PHP-FPM (via CMD). `docker compose run` waits for the container's main process to exit, but PHP-FPM is a daemon.

**Fix**: Always pass an explicit command that exits:
```bash
docker compose run --rm --no-deps <env-vars> php php bin/phpunit --no-coverage
```

## `MockHttpClient` `stream()` / `cancel()` Issue

**Symptom**: PgpKeyServiceTest fails with `ClientException` thrown during `stream()` iteration for 4xx MockResponse objects.

**Root cause**: Symfony's `MockHttpClient::stream()` internally calls `$response->getContent()` (without `$throw = false`) when processing the last chunk. For 4xx responses, this throws `ClientException`.

**Fix approaches tried**:
1. `'http_errors' => false` on request options — doesn't work, `stream()` still throws internally.
2. `try/catch` around the entire `foreach stream()` — catches the exception but loses remaining responses.
3. **Final approach**: Don't use `stream()` at all. Use `getContent(false)` + `getStatusCode()` directly in a sequential foreach. All requests are still dispatched concurrently via `$this->httpClient->request()` (which returns immediately). The `httpClient` handles concurrency internally; we just consume responses sequentially with `getContent(false)`. Cancel remaining in `finally`.

**Key insight**: Symfony's `MockHttpClient` with `http_errors => false` does NOT throw on `getContent(false)` for 4xx responses. The `false` parameter suppresses the throw.

## `PgpSigningService` Constructor Signature Change

When adding `$appEnv` parameter:
- All test instantiations must be updated to pass `'test'`
- `services.yaml` must wire `$appEnv: '%kernel.environment%'`
- The passphrase check fires BEFORE GnuPG initialization, so it throws before any GPG operations
