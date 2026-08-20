# Production Hardening Guide

This guide documents the security configurations and operational practices required to run Lockpost in production.

## 1. Environment Variables

The following environment variables must be configured for production:

| Variable | Required | Default | Description |
|---|---|---|---|
| `APP_ENV` | Yes | `dev` | Must be set to `prod` in production. |
| `APP_DEBUG` | Yes | `1` | Must be `0` in production. Debug mode exposes sensitive information. |
| `APP_SECRET` | Yes | `change-me-to-a-random-32-char-string` | Must be at least 32 cryptographically random characters. A compiler pass (`AppSecretValidationPass`) rejects weak secrets at container build time in production. |
| `APP_MAIL_FROM` | Yes | `noreply@lockpost.local` | The sender address for outbound encrypted messages. Use a real address at your domain. |
| `APP_TOKEN_TTL` | No | `604800` (7 days) | Token link expiration in seconds. Shorter is safer for stateless, non-revocable tokens. Recommended: `86400` (24 hours). |
| `APP_TOKEN_STATEFUL` | No | `0` | Set to `1` to enable stateful token tracking in a file store for revocation, one-time-use, and max submission limits. When `0`, tokens are stateless and non-revocable. |
| `PGP_PRIVATE_KEY_PASSPHRASE` | Yes | — | Passphrase protecting the server's PGP private key. The service refuses to boot if empty in non-dev environments. |
| `MAILER_DSN` | Yes | `smtp://mailhog:1025` | Must point to a real SMTP server with TLS in production. |
| `TRUSTED_PROXIES` | Recommended | `127.0.0.1` | Set to your reverse proxy IP or subnet for correct absolute URL generation. |
| `TRUSTED_HOSTS` | Recommended | — | Set to your domain(s) if behind a reverse proxy. |

## 2. APP_SECRET Generation

The `APP_SECRET` is used to sign and encrypt token links. A weak or predictable secret allows attackers to forge links.

Generate a strong secret:

```bash
# 32 bytes -> 64 hex characters
openssl rand -hex 32

# Or using Python
python3 -c "import secrets; print(secrets.token_hex(32))"
```

> **Important:** The `AppSecretValidationPass` compiler pass enforces a minimum of 32 characters in production. The string `change-me-to-a-random-32-char-string` and other common defaults are explicitly rejected.

## 3. PGP Private Key Permissions

The server's PGP private key is stored at `config/pgp/private-keys-v1.d/`. Even though these files live inside the Docker container (not on the host), the container user should be restricted:

- **Passphrase-protect** the private key. An unencrypted private key on a production server is a critical risk.
- Generate the key with `scripts/init-pgp.sh --with-passphrase <your-strong-passphrase>`
- Set `PGP_PRIVATE_KEY_PASSPHRASE` in your production environment (never commit to source control).
- The Dockerfile sets `chmod -R 700` on `config/pgp/key-config/` and restricts access to `www-data`.
- The key directory is mounted as a Docker volume — consider backing it up.

## 4. TLS / HSTS

Lockpost must be served over HTTPS in production. The NGINX configuration includes HSTS, but it only takes effect when the browser receives it over a valid TLS connection.

For production behind a TLS-terminating reverse proxy (or direct TLS):

### Behind a TLS-terminating proxy (recommended)
The proxy handles TLS termination. Ensure:
- The proxy sets `X-Forwarded-Proto: https`
- `TRUSTED_PROXIES` includes the proxy IP
- The proxy forwards HSTS headers or NGINX sets them directly

### Direct TLS (NGINX handles TLS)
Add to your NGINX server block:
```nginx
listen 443 ssl http2;
ssl_certificate /path/to/fullchain.pem;
ssl_certificate_key /path/to/privkey.pem;
ssl_protocols TLSv1.3 TLSv1.2;
ssl_ciphers HIGH:!aNULL:!MD5;
```

### HSTS
The NGINX config sets `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`.
- This forces browsers to use HTTPS for 1 year.
- Submit your domain to the [HSTS preload list](https://hstspreload.org/) for maximum protection.
- Ensure your entire site (including all subdomains) is HTTPS-compatible before enabling preload.

## 5. Access Log Token Scrubbing

Lockpost scrubs token values and email addresses from application logs via the `TokenScrubbingProcessor` Monolog processor. This replaces token URLs (`/submit/<64-hex-chars>`) and email addresses with `[REDACTED]`.

Additionally, the NGINX configuration disables access logging for sensitive routes:
- `location ~ ^/submit/` — `access_log off`
- `location ~ ^/message/submit` — `access_log off`

This prevents token strings from appearing in NGINX access logs.

For other routes, ensure your log rotation configuration is in place (e.g., via `logrotate` or Docker's log driver).

## 6. PGP Signing Key Backup & Rotation

The server's PGP private key is used to sign outgoing messages. If the key is lost, existing signed messages cannot be re-verified, and key rotation breaks the chain of trust.

**Backup:**
1. The key is stored at `config/pgp/private-keys-v1.d/` inside the PHP container.
2. Export a backup: `docker-compose exec php gpg --homedir /var/www/app/config/pgp/key-config --export-secret-keys > backup.asc`
3. Store the backup offline (encrypted USB, password manager with file storage).
4. Also back up the revocation certificate: `gpg --homedir /var/www/app/config/pgp/key-config --output revoke.crt --gen-revoke <key-id>`

**Rotation:**
1. Generate a new key pair.
2. Update `PGP_PRIVATE_KEY_PASSPHRASE` if the new key has a different passphrase.
3. Announce the new signing key to recipients via the old key (signed transition statement).
4. Keep the old private key until all previously sent messages have been verified.

## 7. Rate Limiter Storage

Lockpost uses Symfony's rate limiters for abuse prevention:

| Limiter | Scope | Limit |
|---|---|---|
| `link_generation` | Per IP | 5 per minute |
| `link_generation_failed` | Per IP | 3 per hour (failed key lookups) |
| `submit_ip` | Per IP | 5 per minute (message submissions) |
| `submit_token` | Per token hash | 10 per hour (message submissions) |

These are configured in `config/packages/framework.yaml` using in-memory storage by default. For production with multiple workers/instances, switch to a shared storage backend:

```yaml
# config/packages/framework.yaml
framework:
    rate_limiter:
        policies:
            link_generation:
                strategy: fixed_window
                limit: 5
                interval: '60 seconds'
                policy: redis  # or 'cache' with a shared cache adapter
```

Use `CACHE_POOL=redis` and configure `framework.cache` to use a shared Redis instance, or use Symfony's `RateLimiter` with a Doctrine or Redis storage adapter.

## 8. Mailer TLS & Authentication

Ensure your SMTP delivery uses TLS and authentication:

```env
# Production SMTP with TLS
MAILER_DSN=smtp://user:password@smtp.example.com:587?encryption=tls&auth_mode=login
```

Or with a Unix socket to a local postfix:
```env
MAILER_DSN=smtp://172.17.0.1:25?encryption=
```

Ensure the mail server does not expose an open relay. Configure postfix to only accept mail from the `www-data` user or the container's IP.

## 9. APP_DEBUG=0

Always set `APP_DEBUG=0` in production. Debug mode:
- Exposes the Symfony Web Debug Toolbar
- Shows detailed exception pages with stack traces
- Can leak sensitive configuration values
- Disables certain caching optimizations

The `.env.production.example` sets `APP_DEBUG=0` by default.

## 10. Container Security

- Run containers as non-root where possible (the PHP-FPM image uses `www-data` for workers).
- Keep Docker images updated (`docker pull php:8.4-fpm`).
- Use `docker-compose.prod.yml` for production-specific overrides.
- Regularly prune unused containers and images: `docker system prune -f`.
- Consider read-only root filesystem for the PHP container (`read_only: true` in compose) with a tmpfs for `var/`.
- Set memory limits for PHP-FPM workers to prevent OOM attacks.

## 11. File Permissions

The `var/` directory (cache, logs) and `var/data/` (if stateful mode is enabled) must be writable by the web server user (`www-data` in the container):

```bash
docker-compose exec php chown -R www-data:www-data var/data
```

The `config/pgp/` directory (key storage) should be read-only except for initial key setup.
