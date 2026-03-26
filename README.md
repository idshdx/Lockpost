# SYM.PGP.ONY

[![GitLab](https://img.shields.io/badge/GitLab-Main_Repository-orange.svg)](https://gitlab.com/zer0lis/sym-pgp-ony)

> This is a mirror repository. Primary development is at [gitlab.com/zer0lis/sym-pgp-ony](https://gitlab.com/zer0lis/sym-pgp-ony).

## Overview

SYM.PGP.ONY lets users receive PGP-encrypted messages through shareable links. It solves the problem of securely receiving sensitive information from people who aren't familiar with encryption.

### How it works

1. You enter your PGP-associated email address to generate a unique, time-limited link
2. The app verifies your public key exists on a public key server
3. You share the link with whoever needs to send you a message
4. They open the link, type their message — it's encrypted in the browser using your public key
5. The server signs the encrypted message and emails it to you
6. You decrypt it with your private PGP key and can verify the server's signature for authenticity

### Design principles

- No message storage — fully stateless, zero persistence
- No tracking or cookies
- Client-side encryption only (OpenPGP.js)
- Stateless tokens using AES-256-CBC + HMAC-SHA256 (30-day expiry)
- Server signs outgoing messages with its own PGP key

---

## Local Development Setup

**Prerequisites:** Docker and Docker Compose.

### 1. Clone and configure environment

```bash
git clone <repo-url>
cd sym-pgp-ony
cp .env.example .env
```

The defaults in `.env.example` work for local Docker dev. The only value you may want to change is `APP_SECRET` — set it to any random string.

### 2. Start containers

```bash
docker-compose up -d
```

This starts three containers: `php` (PHP 8.3-FPM), `nginx` (reverse proxy on port 80), and `mailhog` (local mail catcher).

### 3. Install PHP dependencies

```bash
docker exec php composer install
```

### 4. Generate the server PGP key pair

The app requires a PGP key pair to sign outgoing messages. Run this once:

```bash
docker exec php bash /var/www/app/scripts/init-pgp.sh
```

This generates `config/pgp/private.key` and `config/pgp/public.key` inside the container. These files are gitignored and never committed.

### 5. Fix file permissions

The PHP-FPM process runs as `www-data`. After key generation (which runs as root), fix ownership:

```bash
docker exec php bash -c "chown -R www-data:www-data /var/www/app/var/ /var/www/app/config/pgp/"
```

### 6. Verify

The app is available at **http://localhost**.
MailHog (inspect outgoing emails) is at **http://localhost:8025**.

```bash
# Quick smoke test — boots the kernel and hits the / route
docker exec php php bin/phpunit tests/BootstrapTest.php --no-coverage
```

---

## Environment Variables

Defined in `.env` (copy from `.env.example`):

| Variable | Description |
|---|---|
| `APP_ENV` | `dev` for local, `prod` for production |
| `APP_SECRET` | Random secret used for token encryption — change this |
| `MAILER_DSN` | SMTP connection string. Default points to MailHog: `smtp://mailhog:1025` |
| `MESSENGER_TRANSPORT_DSN` | Messenger transport. Default: `doctrine://default?auto_setup=0` |
| `PGP_PRIVATE_KEY_PASSPHRASE` | Passphrase for the server's PGP private key. The default `init-pgp.sh` script generates keys with no passphrase (`%no-protection`), so leave this as the placeholder or set it to empty |

---

## Running Tests

```bash
# Full test suite
docker exec php php bin/phpunit --no-coverage

# Specific file
docker exec php php bin/phpunit tests/BootstrapTest.php --no-coverage
docker exec php php bin/phpunit tests/Service/PgpSigningServiceTest.php --no-coverage

# With coverage report
docker exec php php bin/phpunit --coverage-text
```

---

## Common Commands

```bash
# Clear Symfony cache
docker exec php php bin/console cache:clear

# Tail application logs
docker exec php tail -f var/log/dev.log

# Reinstall JS importmap assets
docker exec php php bin/console importmap:install

# Stop all containers
docker-compose down
```

---

## Architecture

### Services

| Service | Responsibility |
|---|---|
| `TokenLinkService` | Generates and validates time-limited encrypted tokens (AES-256-CBC + HMAC-SHA256) |
| `PgpKeyService` | Looks up public keys from key servers (keys.openpgp.org, keyserver.ubuntu.com, pgp.mit.edu) |
| `PgpSigningService` | Signs outgoing messages and verifies signatures using the server's GnuPG key |

### Tech stack

- **Backend:** PHP 8.3, Symfony 7.1
- **Frontend:** Stimulus, Turbo, Symfony AssetMapper, OpenPGP.js, Bootstrap
- **Infrastructure:** Docker, NGINX, PHP-FPM, MailHog
- **Testing:** PHPUnit 9.5

### PGP key storage

Keys live in `config/pgp/` (gitignored):

```
config/pgp/
  private.key       # Server signing key  (chmod 600, owner www-data)
  public.key        # Server public key   (chmod 644, owner www-data)
  key-config/       # GnuPG home directory
    gpg.conf        # GPG config (pinentry-mode loopback, no-protection)
```

---

## Future Plans

- HSM integration
- Mobile support
- Localization
- Advanced key management / automated rotation
