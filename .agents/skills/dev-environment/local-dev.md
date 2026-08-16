---
skill: local-dev
domain: dev-environment
title: Local Dev Environment & Application Startup
description: Setting up and running the Lockpost Symfony PHP application locally.
---

# Local Development Environment

**Use when:** Setting up or running the Lockpost Symfony PHP application locally.

## Prerequisites
- Docker
- Git

## Setup Steps

### 1. Clone and Enter
```bash
git clone <repository-url> && cd sym-pgp-ony
```

### 2. Copy Environment
```bash
cp .env.example .env
```
Edit `.env` — set `APP_SECRET` to a random 32-char string. The default `PGP_PRIVATE_KEY_PASSPHRASE=your-secure-passphrase` is fine for local dev.

### 3. Build and Start Containers
```bash
docker compose up --build -d
```
Services: nginx (port **8080**), php-fpm (internal), mailhog (port 1025 SMTP, 8025 web UI).

> **Port note:** If port 80 is free, change `"8080:80"` to `"80:80"` in `docker-compose.yml`. If port 80 is occupied (e.g. by another local web server), keep `8080:80`.

### 4. Install PHP Dependencies
```bash
docker compose exec php composer install --no-scripts
```
No `--ignore-platform-req=ext-opcache` needed — the `composer.json` already has `"platform-check": false` and `"ext-opcache": "8.3"` in `config.platform`.

### 5. Install Frontend Assets (importmap)
```bash
docker compose exec php php bin/console importmap:require @hotwired/stimulus openpgp
docker compose exec php php bin/console importmap:install
```
This downloads Stimulus and OpenPGP.js into `assets/vendor/` and creates `importmap.php`.

### 6. Generate PGP Keys (first-time only)
```bash
docker compose exec php bash /var/www/app/scripts/init-pgp.sh --with-passphrase your-secure-passphrase
```
Or without passphrase (dev convenience only):
```bash
docker compose exec php bash /var/www/app/scripts/init-pgp.sh
```
This creates `config/pgp/private.key` and `config/pgp/public.key` (gitignored).

### 7. Fix File Permissions
PHP-FPM runs as `www-data`. Fix ownership so the FPM worker can read PGP keys and write to var/cache:
```bash
docker compose exec php bash -c "chown -R www-data:www-data /var/www/app/var/ /var/www/app/config/pgp/"
```
> **Windows note:** Always use `bash -c "..."` wrapper for chown commands to avoid path translation issues.

### 8. Access the Application
- Web: http://localhost:8080
- MailHog: http://localhost:8025

## Troubleshooting
- **Container won't start / hangs**: The `entrypoint.sh` runs `chown -R www-data:www-data` on startup, which can be slow on some systems with large volumes. Check with `docker compose logs php`.
- **GNUPGHOME mismatch**: The container sets `GNUPGHOME=/var/www/app/config/pgp/key-config` via environment. Ensure `.env` has matching `PGP_PRIVATE_KEY_PASSPHRASE`.
- **Cache write failures**: Run the chown command in Step 7. Also clear cache: `docker compose exec php php bin/console cache:clear`.
- **502 Bad Gateway from nginx**: PHP-FPM may not be ready. Wait 2-3 seconds and retry.
- **404 on /server-key**: Ensure PGP keys were generated and permissions are set (Step 7).
- **Template errors about missing assets**: Run the importmap commands from Step 5.
