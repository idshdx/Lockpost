# Lockpost

> *Share a link. Receive a secret. Leave no trace.*

---

## Overview

Lockpost lets users receive PGP-encrypted messages through shareable links. It solves the problem of securely receiving sensitive information from people who aren't familiar with encryption.

---

## How it works

**1. Generate a link**

Enter your PGP-associated email address. The app looks up your public key on public key servers and generates a unique, time-limited shareable link.

![Generate a secure link](docs/screenshots/generate-link.png)

---

**2. Share the link**

Copy the link and send it to whoever needs to contact you — by email, chat, or any other channel.

![Shareable link ready](docs/screenshots/shareable-link.png)

---

**3. Recipient writes a message**

The recipient opens the link and types their message. It is encrypted entirely in their browser using your public key before anything leaves their device.

![Write and encrypt a message](docs/screenshots/write-message.png)

---

**4. You receive the email**

The server signs the encrypted message with its own PGP key and forwards it to your inbox. The email contains the signed message, the raw encrypted block, and the server's public key.

![Email received](docs/screenshots/email-received.png)

---

**5. Verify the server's signature (optional)**

Paste the signed message and the server's public key into the Verify page to confirm the message was genuinely forwarded by this server and was not tampered with in transit. Verification runs entirely in the browser.

![Verify the server signature](docs/screenshots/verify-signature.png)

---

## Design principles

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
docker compose up --build -d
```

This starts three containers: `php` (PHP 8.3-FPM with Xdebug), `nginx` (reverse proxy on port **8080**), and `mailhog` (local mail catcher on port 8025).

> **Note on ports:** If port 80 is already in use by another service (e.g. YunoHost), the app is available at **http://localhost:8080**. If port 80 is free, you can change the port mapping in `docker-compose.yml` from `"8080:80"` to `"80:80"`.

### 3. Install PHP dependencies

```bash
docker compose exec php composer install --no-scripts
```

> **Note:** `--no-scripts` skips the auto-scripts (cache:clear, assets:install, importmap:install). Run the next step to install frontend assets.

### 4. Install frontend assets (importmap)

```bash
docker compose exec php php bin/console importmap:require @hotwired/stimulus openpgp
docker compose exec php php bin/console importmap:install
```

This downloads Stimulus and OpenPGP.js into `assets/vendor/` and registers them in `importmap.php`.

### 5. Generate the server PGP key pair

The app requires a PGP key pair to sign outgoing messages. Run this once:

```bash
docker compose exec php bash /var/www/app/scripts/init-pgp.sh --with-passphrase your-secure-passphrase
```

Or without a passphrase (for local dev only):

```bash
docker compose exec php bash /var/www/app/scripts/init-pgp.sh
```

This generates `config/pgp/private.key` and `config/pgp/public.key` inside the container. These files are gitignored and never committed.

### 6. Fix file permissions

The PHP-FPM process runs as `www-data`. After key generation (which runs as root), fix ownership:

```bash
docker compose exec php bash -c "chown -R www-data:www-data /var/www/app/var/ /var/www/app/config/pgp/"
```

> **Windows users:** If the above `chown` fails with a `cannot access` error, it's because `docker exec` resolves paths against the Windows filesystem. Always wrap `chown` paths in `bash -c "..."` as shown above.

### 7. Verify

The app is available at **http://localhost:8080**.
MailHog (inspect outgoing emails) is at **http://localhost:8025**.

```bash
# Quick smoke test
docker compose exec php php bin/phpunit tests/BootstrapTest.php --no-coverage
```

---

## Environment Variables

Defined in `.env` (copy from `.env.example`):

| Variable | Description |
|---|---|
| `APP_ENV` | `dev` for local, `prod` for production |
| `APP_SECRET` | Random secret used for token encryption — change this |
| `MAILER_DSN` | SMTP connection string. Default points to MailHog: `smtp://mailhog:1025` |
| `MESSENGER_TRANSPORT_DSN` | Messenger transport. Default: `sync://` |
| `PGP_PRIVATE_KEY_PASSPHRASE` | Passphrase for the server's PGP private key. Required in production. The default `init-pgp.sh` generates keys with no passphrase (`%no-protection`), so leave this as the placeholder or set it to empty for local dev |

---

## Running Tests

```bash
# Full test suite
docker compose exec php php bin/phpunit --no-coverage

# Specific file
docker compose exec php php bin/phpunit tests/BootstrapTest.php --no-coverage
docker compose exec php php bin/phpunit tests/Service/PgpSigningServiceTest.php --no-coverage

# With coverage report (requires Xdebug — dev image only)
docker compose exec php php bin/phpunit --coverage-text

# Using docker compose run (fresh container, no service start required)
docker compose run --rm --no-deps \
  -e APP_ENV=test \
  -e APP_SECRET=test-secret-32-chars-long!! \
  -e PGP_PRIVATE_KEY_PASSPHRASE=your-secure-passphrase \
  -e APP_MAIL_FROM=noreply@lockpost.local \
  -e MAILER_DSN=smtp://mailhog:1025 \
  -e GNUPGHOME=/var/www/app/config/pgp/key-config \
  php php bin/phpunit --no-coverage
```

For tests that require the GPG keyring (PgpSigningService, DefaultController):
1. Generate PGP keys first (step 5 above)
2. Ensure `GNUPGHOME=/var/www/app/config/pgp/key-config` is set in the container

For tests that do NOT require GPG (TokenLinkService, PgpKeyService):
- Use `docker compose run --rm --no-deps -e APP_ENV=test -e APP_SECRET=test-secret-32-chars-long!! php php bin/phpunit tests/Service/TokenLinkServiceTest.php --no-coverage`

---

## Common Commands

```bash
# Clear Symfony cache
docker compose exec php php bin/console cache:clear

# Tail application logs
docker compose exec php tail -f var/log/dev.log

# Reinstall JS importmap assets
docker compose exec php php bin/console importmap:require @hotwired/stimulus openpgp
docker compose exec php php bin/console importmap:install

# Stop all containers
docker compose down
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
- **Frontend:** Stimulus, Symfony AssetMapper, OpenPGP.js, Bootstrap 5
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

## Project History

Lockpost started as a project for *Advanced Programming Techniques* TAP, originally made at [gitlab.com/zer0lis/sym-pgp-ony](https://gitlab.com/zer0lis/sym-pgp-ony)
It has since been extended into a second version and named Lockpost, as a reminder I never never knew how to type symphony, symfony or simfony

---

## Future Plans

- Deploy it live
- Better looks
