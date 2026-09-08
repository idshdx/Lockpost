---
name: VPS Deployment Checklist
related_skill: symfony-deployment-audit
created: 2026-08-16
---

# VPS Deployment Checklist — Symfony/NGINX/PHP-FPM on Oracle Cloud

Battle-tested runbook for deploying the Lockpost (sym-pgp-ony) Symfony 7.1
application to an Oracle Cloud Ubuntu VPS using Docker Compose with
`network_mode: host`. Covers preconditions, ordering, and common failure
modes discovered during live deployment.

## Prerequisites

- Oracle Cloud VPS (Ubuntu 24.04) with public IP (e.g. 129.159.7.42)
- SSH key access (Ubuntu user, sudo rights)
- OCI Security List ingress rules for TCP 80 and 443 from 0.0.0.0/0
- Domain pointing to the VPS IP (optional, for TLS later)

## Step 1 — Server OS preparation

```bash
sudo apt-get update && sudo apt-get install -y git curl ca-certificates gnupg ufw
```

## Step 2 — Firewall (ufw)

```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
echo "y" | sudo ufw enable
sudo ufw status
```

**Pitfall — Oracle Cloud Security List (cloud-level firewall):**
The Oracle Cloud default Security List only allows ingress on TCP 22.
Ports 80/443 must be added **in the OCI Console** under
Networking → VCN → Security Lists → Ingress Rules:
  - Source: 0.0.0.0/0, Protocol: TCP, Port: 80
  - Source: 0.0.0.0/0, Protocol: TCP, Port: 443
Without this, external traffic to port 80 times out even if ufw allows it
and the app responds on localhost.

**Pitfall — Oracle Security List `source-port-range` on ingress rules:**
When adding ingress rules for ports 80/443 via the OCI Console or CLI, the
UI may auto-populate `source-port-range` matching the destination port.
For **ingress** rules, `source-port-range` filters on the **client's** source
port — legitimate web browsers connect from ephemeral ports (1024–65535),
never port 80/443. A rule requiring source port 80/443 silently drops ALL
web traffic, even though the security list "looks" correct. **Always leave
`source-port-range` unset** on ingress rules — only set `destination-port-range`.
This is the single most common reason for "Security List has port 80 open but
external curl times out."

Fix via CLI (note: no `sourcePortRange` key in the JSON):
```bash
oci network security-list update --security-list-id <SL_ID> --profile DEFAULT \
  --ingress-security-rules '[{"description":"HTTP","isStateless":false,"protocol":"6","source":"0.0.0.0/0","sourceType":"CIDR_BLOCK","tcpOptions":{"destinationPortRange":{"min":80,"max":80}}}]' \
  --force
```

**Pitfall — iptables REJECT before ufw rules:**
On some VPS images, a blanket `REJECT` rule exists in the INPUT chain
*before* the ufw rule chain. This silently drops all inbound traffic to
non-SSH ports. Diagnose with `sudo iptables -L INPUT -n --line-numbers`
and remove the offending REJECT rule with `sudo iptables -D INPUT <N>`.
Save with `iptables-save > /etc/iptables-rules.v4`.

## Step 3 — Docker

```bash
# Docker Engine 24+ and Docker Compose v2
curl -fsSL https://get.docker.com -o /tmp/get-docker.sh
sudo sh /tmp/get-docker.sh
sudo docker compose version
```

## Step 4 — Clone repo

```bash
sudo mkdir -p /opt/lockpost
sudo chown -R "$(whoami)":"$(whoami)" /opt/lockpost
git clone https://github.com/idshdx/lockpost /opt/lockpost
cd /opt/lockpost
```

## Step 5 — CRLF line ending fix (critical for Windows-cloned repos)

If the repo was cloned or files transferred from a Windows host, fix CRLF
line endings before starting any container:

```bash
for f in docker/php/entrypoint.sh scripts/*.sh docker-compose*.yml .env*; do
  if [ -f "$f" ]; then
    sed -i 's/\r$//' "$f"
  fi
done
```

**Why:** `entrypoint.sh` with `#!/bin/bash\r` produces
`exec /usr/local/bin/entrypoint.sh: no such file or directory` because
the `\r` is part of the interpreter path. The container crashes repeatedly
with exit code 255 and `docker compose up` shows `Restarting (255)`.

## Step 6 — Environment (.env for production)

```bash
cp .env.production.example .env
```

Set in `.env`:
- `APP_SECRET` — `openssl rand -hex 32`
- `APP_MAIL_FROM` — real sender at your domain
- `PGP_PRIVATE_KEY_PASSPHRASE` — strong secret (required in prod!)
- `MAILER_DSN` — `smtp://127.0.0.1:25` (host networking, Postfix on host)

**App-version-dependent env vars** (add as needed based on the new version):
- `LOCK_DSN=flock` — Symfony Lock backend. Use `flock` (no PHP extension needed). `semaphore` requires `sysvsem` which is NOT installed in Alpine PHP by default.
- `TRUSTED_PROXIES=127.0.0.1,172.18.0.0/16` — needed for `network_mode: host` with Symfony trusted_proxies config
- `APP_TOKEN_STATEFUL=true` — required by the stateful link mode feature (TokenScrubbingProcessor + SecurityHeadersListener)

## Step 7 — Postfix (host-level, port 25)

```bash
export DEBIAN_FRONTEND=noninteractive
sudo apt-get install -y postfix
sudo postconf -e "inet_interfaces = loopback-only"
sudo postconf -e "mynetworks = 127.0.0.0/8"
sudo systemctl restart postfix
sudo systemctl enable postfix
```

Verify: `telnet 127.0.0.1 25` should show `220 ... ESMTP Postfix`.

## Step 8 — Build PHP container and generate PGP keys

```bash
docker compose -f docker-compose.prod.yml build php
docker compose -f docker-compose.prod.yml up -d php

# Generate PGP key with passphrase (extract passphrase from .env)
PASSPHRASE=$(grep "^PGP_PRIVATE_KEY_PASSPHRASE=" .env | cut -d= -f2-)
docker compose -f docker-compose.prod.yml exec php sh /var/www/app/scripts/init-pgp.sh --with-passphrase "$PASSPHRASE"

# Fix ownership and permissions (non-root appuser, not www-data)
docker compose -f docker-compose.prod.yml exec php sh -c \
  "chown -R appuser:appgroup /var/www/app/config/pgp /var/www/app/var"
```

**Pitfall — PgpSigningService refuses boot without passphrase in prod:**
The service throws `PGP private key passphrase is required in non-dev
environments` if `PGP_PRIVATE_KEY_PASSPHRASE` is empty. Must be set in `.env`.

## Step 9 — Install dependencies and frontend assets

```bash
docker compose -f docker-compose.prod.yml exec php composer install --no-scripts --no-interaction
docker compose -f docker-compose.prod.yml exec php php bin/console importmap:install --no-interaction
docker compose -f docker-compose.prod.yml exec php php bin/console importmap:require openpgp --no-interaction
```

**Pitfall — Missing importmap packages cause 500 on route rendering:**
`templates/base.html.twig` uses `{{ importmap('app') }}` which requires all
entries in `importmap.php` to have downloaded vendor assets. If
`@hotwired/stimulus` or `openpgp` haven't been installed, the homepage
returns 500 with `"@hotwired/stimulus" vendor asset is missing`.

## Step 10 — Cache warmup

```bash
docker compose -f docker-compose.prod.yml exec php sh -c "
  export APP_ENV=prod APP_DEBUG=0
  php bin/console cache:clear --no-debug
  php bin/console cache:warmup --no-debug
  php bin/console asset-map:compile
  chown -R appuser:appgroup var/
"
```

**Pitfall — Test environment env var conflict with prod .env:**
When the container uses `env_file: .env` (setting `APP_ENV=prod`), running
PHPUnit fails because `phpunit.xml.dist` sets `<env name="APP_ENV" value="test"/>`
but the container-level env var takes precedence. Symfony's `Dotenv::bootEnv()`
does not override existing env vars. Fix by explicitly exporting when running
tests:

```bash
docker compose -f docker-compose.prod.yml exec php bash -c "
  export APP_ENV=test
  export APP_DEBUG=1
  php bin/phpunit tests/BootstrapTest.php --no-coverage
"
```

## Step 11 — Start full stack and smoke test

```bash
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml ps

# Internal tests
curl -s -o /dev/null -w "%{http_code}" http://localhost/   # expect 200
curl -s http://localhost/server-key | grep "BEGIN PGP PUBLIC KEY BLOCK"

# Bootstrap test
docker compose -f docker-compose.prod.yml exec php bash -c "
  export APP_ENV=test
  export APP_DEBUG=1
  php bin/phpunit tests/BootstrapTest.php --no-coverage
"
```

## Step 12 — External verification

From a machine outside the VPS:
```bash
curl -s -o /dev/null -w "%{http_code}" http://<VPS_PUBLIC_IP>/   # expect 200
curl -s http://<VPS_PUBLIC_IP>/server-key | grep "BEGIN PGP PUBLIC KEY"
```

**Security Checklist**

- [x] `PGP_PRIVATE_KEY_PASSPHRASE` is set and strong (64-char hex, not in git)
- [x] `config/pgp/private.key` has 600 permissions, owned by appuser
- [x] `config/pgp/public.key` has 644 permissions
- [x] `config/pgp/` and `key-config/` have 700 permissions
- [x] Postfix listens on loopback only (not exposed)
- [x] ufw active, only ports 22/80/443 open to 0.0.0.0/0

## Container Security Checklist (Hardening Updates)

- [x] PHP container runs as non-root user `appuser` (uid 1000) — not www-data
- [x] `no-new-privileges: true` on all containers (nginx, php, cron)
- [x] Nginx container: `read_only: true` with tmpfs for /var/cache, /var/run, /var/log
- [x] PHP container: tmpfs for /var/www/app/var (cache/logs) and /tmp
- [x] Resource limits: nginx 256M/0.5cpu, php 512M/1.0cpu, cron 128M/0.2cpu
- [x] Healthcheck on PHP container (verifies gnupg extension loaded)
- [x] Security headers in nginx: CSP, X-Frame-Options DENY, X-Content-Type-Options, Referrer-Policy, Permissions-Policy
- [x] Rate limiting: 10r/s general burst 20, login endpoint limited
- [x] Sensitive paths denied in nginx: /config/, /var/, /tests/, /src/, .env, .git
- [x] PHP-FPM error_log redirected to /var/www/app/var/log/ (not /proc/self/fd/2, fails as non-root)
- [x] Alpine-based images: `#!/bin/sh` shebang, `su-exec` explicitly installed
