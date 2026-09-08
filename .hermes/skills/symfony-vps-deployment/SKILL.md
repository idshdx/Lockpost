---
name: symfony-vps-deployment
description: Deploy and harden Symfony apps on Oracle Cloud VPS.
version: 1.0.0
author: Mihai (idshdx), Hermes Agent
license: MIT
platforms: [linux, windows]
metadata:
  hermes:
    tags: [symfony, docker, vps, oracle-cloud, deployment, hardening, pgp]
    related_skills: [symfony-deployment-audit, oci-always-free]
---

# Symfony VPS Deployment

## Overview

Deploy a hardened Symfony 7.x PHP application (Lockpost sym-pgp-ony) to an Oracle Cloud Ubuntu 24.04 VPS with Docker Compose, including security hardening, PGP key management, fail2ban, swap, and a VPS-hosted Hermes Agent for ongoing maintenance.

## When to Use

- Deploying a new version of the app to the VPS
- Setting up a fresh VPS from scratch
- Post-deployment hardening (firewall, swap, fail2ban, SSH lockdown)
- Installing Hermes Agent on the VPS for autonomous monitoring

## Prerequisites

- ✅ VPS: Ubuntu 24.04 ARM, public IP, SSH access (port 2222, key-only)
- ✅ SSH key: `~/.ssh/ssh-key-oracle.key` (or configured alias)
- ✅ Docker Compose project at `/opt/lockpost` on VPS
- ✅ Local repo at `C:/Users/mihai/Documents/GitHub/sym-pgp-ony`
- ✅ Hermes Agent installed locally at `~/.hermes/venv/bin/hermes`

### Environment Variables (VPS `.env`)

| Variable | Purpose | Example |
|---|---|---|
| `APP_SECRET` | Symfony secret (64-char hex) | `openssl rand -hex 32` |
| `PGP_PRIVATE_KEY_PASSPHRASE` | PGP key passphrase (64-char hex) | `openssl rand -hex 32` |
| `MAILER_DSN` | SMTP to host Postfix | `smtp://127.0.0.1:25` |
| `APP_MAIL_FROM` | Email sender address | `lockpost@129.159.7.42` |
| `TRUSTED_PROXIES` | Symfony trusted proxies CIDR | `127.0.0.1,172.18.0.0/16` |
| `LOCK_DSN` | Symfony lock backend | `flock` (use `flock`, not `semaphore` — Alpine PHP lacks `sysvsem` extension) |
| `APP_TOKEN_TTL` | Token expiration (days) | `7` (changed from 30) |

## VPS Setup Runbook

### 1. Install Packages & Swap

```bash
# System packages
sudo apt-get update && sudo apt-get install -y curl jq unzip zip software-properties-common

# 2GB swap file (essential for 954MB RAM instance)
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
echo 'vm.swappiness=10' | sudo tee -a /etc/sysctl.conf
sudo sysctl -p
```

### 2. SSH Hardening

```bash
sudo cp /etc/ssh/sshd_config /etc/ssh/sshd_config.bak
echo 'Port 2222
PermitRootLogin no
PasswordAuthentication no
PubkeyAuthentication yes
MaxAuthTries 3
AllowUsers ubuntu' | sudo tee /etc/ssh/sshd_config.d/99-hardening.conf
sudo systemctl restart ssh
```

### 3. Firewall & Intrusion Detection

```bash
# UFW (reinstall if stale rules exist)
sudo apt-get remove --purge -y iptables-persistent 2>/dev/null || true
sudo apt-get install -y ufw
sudo ufw default deny incoming
sudo ufw allow 2222/tcp  # SSH
sudo ufw allow 80/tcp    # HTTP
sudo ufw allow 443/tcp   # HTTPS
sudo ufw --force enable

# Fail2ban
sudo apt-get install -y fail2ban
sudo systemctl enable fail2ban && sudo systemctl start fail2ban
```

Fail2ban config at `/etc/fail2ban/jail.local`:
```ini
[sshd]
enabled = true
port = 2222
filter = sshd
logpath = /var/log/auth.log
maxretry = 3
bantime = 3600
```

### 4. Install Docker & Compose

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker ubuntu
sudo apt-get install -y docker-compose-v2
# Log out/in or `newgrp docker`
```

### 5. Deploy App Code

```bash
sudo mkdir -p /opt/lockpost
sudo chown ubuntu:ubuntu /opt/lockpost
cd /opt/lockpost

# Initial clone (or git pull for updates)
git clone https://github.com/idshdx/lockpost.git .
# For updates: git pull origin main

# Fix CRLF line endings (common after Windows-origin file transfer)
find docker/ -type f -exec sed -i 's/\r$//' {} +

# Copy production env
cp .env.production.example .env
# Edit .env: set APP_SECRET, PGP passphrase, etc.
```

### 6. PGP Key Generation

```bash
# Create PGP config directory
mkdir -p config/pgp
chmod 700 config/pgp

# Generate 4096-bit RSA key with passphrase
# Use: scripts/init-pgp.sh or manual gpg batch
chmod 600 config/pgp/*.key config/pgp/*.gpg
```

### 7. Postfix (Host-Level, Loopback Only)

```bash
sudo apt-get install -y postfix
sudo debconf-set-selections <<< "postfix postfix/mailname string 129.159.7.42"
sudo debconf-set-selections <<< "postfix postfix/main_mailer_type string 'Internet Site'"
sudo postconf -e 'inet_interfaces = loopback-only'
sudo postconf -e 'mynetworks = 127.0.0.0/8'
sudo systemctl restart postfix
```

### 8. Oracle Cloud Security List

Open ports 80/443 in the OCI Console (Networking → Virtual Cloud Network → Security Lists → Edit Ingress Rules):
- Source: `0.0.0.0/0`
- Protocol: `TCP`
- Port Range: `80` (HTTP), `443` (HTTPS)

## Container Hardening Config

### docker-compose.prod.yml (key sections)

```yaml
services:
  php:
    build:
      context: ./docker
      dockerfile: php/Dockerfile
    env_file: .env
    security_opt:
      - no-new-privileges:true
    read_only: false
    tmpfs:
      - /tmp
    healthcheck:
      test: ["CMD", "php", "-r", "exit(extension_loaded('gnupg') ? 0 : 1);"]
      interval: 30s
      timeout: 5s
      retries: 3
    deploy:
      resources:
        limits:
          memory: 512M
          cpus: '1.0'

  nginx:
    build:
      context: ./docker
      dockerfile: nginx/Dockerfile
    depends_on:
      - php
    security_opt:
      - no-new-privileges:true
    read_only: true
    tmpfs:
      - /var/cache/nginx
      - /var/run
    deploy:
      resources:
        limits:
          memory: 256M
          cpus: '0.5'

  cron:
    build:
      context: ./docker
      dockerfile: php/Dockerfile
    env_file: .env
    command: /bin/sh -c 'while true; do sleep 60; done'
    security_opt:
      - no-new-privileges:true
    deploy:
      resources:
        limits:
          memory: 128M
          cpus: '0.2'
```

### Docker PHP Dockerfile (Alpine, non-root)

Key requirements:
- `#!/bin/sh` in entrypoint (NOT `#!/bin/bash` — Alpine lacks bash)
- `apk add su-exec autoconf build-base linux-headers libzip-dev gnupg` in build stage
- Entrypoint creates var dirs, fixes permissions, then drops to `appuser` via `su-exec appuser php-fpm`
- Remove `USER appuser` directive from Dockerfile (entrypoint handles privilege drop)
- PHP-FPM `error_log` → `/var/www/app/var/log/php-fpm.log` (NOT `/proc/self/fd/2` — fails as non-root)

### NGINX Security Headers

In `docker/nginx/default.conf`:
```nginx
# Rate limiting
limit_req zone=general burst=20 nodelay;
limit_req zone=login burst=5 nodelay;

# Security headers
add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' data:";
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "geolocation=(), microphone=(), camera=(), payment=()" always;

# Deny sensitive paths
location ~ ^/(config|var|src|tests|vendor)/ { deny all; }
location ~ /\.(env|git|htaccess) { deny all; }
```

## App Update Deployment Workflow

### Option A: SaltStack SSH (Recommended)

Uses salt-ssh to orchestrate deployment without installing a minion on the VPS.

**Prerequisites:**
```bash
pip install salt-ssh
```

**Configuration files** (in `salt/` directory):
- `salt/roster` — VPS connection details
- `salt/master` — Salt master config pointing to roster
- `salt/states/lockpost.sls` — Deployment state definitions

**Deploy:**
```bash
cd /path/to/local/repo
salt-ssh -c salt/master lockpost-vps state.apply lockpost
```

This single command:
1. Pulls latest code from GitHub
2. Fixes CRLF line endings
3. Rebuilds PHP and NGINX containers
4. Runs Composer install
5. Clears and warms cache
6. Runs BootstrapTest
7. Verifies HTTP endpoints

### Option B: Manual SSH

When a new version is published:

```bash
# 1. SSH to VPS
ssh -i ~/.ssh/ssh-key-oracle.key ubuntu@129.159.7.42

# 2. Pull latest code
cd /opt/lockpost
git init && git remote add origin https://github.com/idshdx/lockpost.git
git fetch origin && git reset --hard origin/main

# 3. Fix line endings
find docker/ -type f -exec sed -i 's/\r$//' {} +

# 4. Rebuild containers (required after Dockerfile or nginx config changes)
docker compose -f docker-compose.prod.yml build --no-cache php nginx

# 5. Check for new required env vars
# If LOCK_DSN missing: echo 'LOCK_DSN=flock' >> .env
# If TRUSTED_PROXIES missing: echo 'TRUSTED_PROXIES=127.0.0.1,172.18.0.0/16' >> .env

# 6. Clear ALL caches
docker compose -f docker-compose.prod.yml exec php sh -c \
  'rm -rf /var/www/app/var/cache/prod/* /var/www/app/var/cache/test/*'

# 7. Clear + warm prod cache
docker compose -f docker-compose.prod.yml exec php sh -c \
  'export APP_ENV=prod APP_DEBUG=0; php bin/console cache:clear; php bin/console cache:warmup; chown -R appuser:appgroup var/'

# 8. Run BootstrapTest
docker compose -f docker-compose.prod.yml exec php sh -c \
  'export APP_ENV=test APP_DEBUG=1; php bin/phpunit tests/BootstrapTest.php --no-coverage'

# 9. Restart containers
docker compose -f docker-compose.prod.yml restart php nginx

# 10. Verify external access
curl -s -o /dev/null -w "HTTP %{http_code}" http://127.0.0.1:80/
curl -s http://127.0.0.1:80/server-key | head -2

# 11. Sync changes back to local repo
rsync -av --exclude='vendor/' --exclude='var/cache/*' /opt/lockpost/ /path/to/local/
```

## Hermes Agent on VPS

### Installation

```bash
# On VPS
curl -fsSL https://hermes-agent.nousresearch.com/install.sh | bash

# Install missing system deps if needed
sudo apt-get install -y libatomic1  # for Node.js

# Create venv and install
/home/ubuntu/.hermes/bin/uv venv /home/ubuntu/.hermes/venv
/home/ubuntu/.hermes/bin/uv pip install --python /home/ubuntu/.hermes/venv/bin/python hermes-agent

# Configure
export PATH="/home/ubuntu/.hermes/venv/bin:$PATH"
export HERMES_HOME=/home/ubuntu/.hermes
hermes config set model.default poolside/laguna-s-2.1:free
hermes config set model.provider nous
# Copy auth.json from local machine
scp ~/.hermes/auth.json ubuntu@VPS_IP:/home/ubuntu/.hermes/auth.json

# Create wrapper script
cat > ~/hermes-vps << 'EOF'
#!/bin/bash
export PATH=/home/ubuntu/.hermes/venv/bin:$PATH
export HERMES_HOME=/home/ubuntu/.hermes
"$@"
EOF
chmod +x ~/hermes-vps
# Add to .bashrc: alias hermes-vps="/home/ubuntu/hermes-vps"
```

### Usage (from local machine)

```bash
# Run a task on the VPS via the agent
ssh -i ~/.ssh/ssh-key-oracle.key ubuntu@129.159.7.42 \
  "/home/ubuntu/.hermes/venv/bin/hermes chat -q 'check docker container status and restart any that are down' 2>&1"

# Or with the wrapper
ssh -i ~/.ssh/ssh-key-oracle.key ubuntu@129.159.7.42 \
  "hermes-vps chat -q 'check if lockpost app is responding at localhost' 2>&1"
```

## Common Deployment Gotchas

| Symptom | Fix |
|---|---|
| `LOCK_DSN` env not found | Add `LOCK_DSN=flock` to `.env` (not `semaphore` — Alpine lacks `sysvsem`) |
| CSRF `token_id` unrecognized | Run `composer update symfony/* --with-all-dependencies` (need Symfony 7.4+) |
| PHPUnit fails with `ENV test not found` | Must `export APP_ENV=test APP_DEBUG=1` before running phpunit |
| PHP-FPM crash (`exit 78`) | Change `error_log` from `/proc/self/fd/2` to writable file path |
| `su-exec: command not found` | `apk add su-exec` in Dockerfile |
| Container crash loop (exit 255) | Check for CRLF line endings: `sed -i 's/\r$//'` on shell scripts |
| HTTP 500 on homepage | Run `importmap:install` and `importmap:require openpgp` |
| `TRUSTED_PROXIES` not set | Add to `.env`: `TRUSTED_PROXIES=127.0.0.1,172.18.0.0/16` |

## Pitfalls

1. **Server-first workflow**: User prefers testing on the VPS first, then syncing to local repo. Always do this for any change.
2. **CRLF line endings**: Any file transferred from Windows hosts may have CRLF. Always `sed -i 's/\r$//'` on shell scripts and Docker files.
3. **Stale iptables REJECT**: On fresh Ubuntu, a blanket REJECT rule before ufw chain silently drops traffic. Remove it: `sudo iptables -D INPUT <N>` (diagnose with `iptables -L INPUT -n --line-numbers`).
4. **Oracle Security List**: Default only allows SSH (22). Must manually add ingress rules for 80/443.
5. **`network_mode: host`**: No port mapping needed; host IP == app IP. But this means port conflicts are real — check with `sudo ss -tlnp`.
6. **ARM architecture**: Docker images must match ARM64. Remove any `--platform=linux/amd64` overrides from Dockerfiles.
7. **Resource constraints**: 2 vCPU / 954MB RAM is tight. 2GB swap is mandatory. Container resource limits prevent OOM kills.
8. **Test env override**: `env_file: .env` sets `APP_ENV=prod` at container level. PHPUnit overrides via putenv don't work if cache is stale. Always `export APP_ENV=test` explicitly.

## Verification Checklist

- [ ] BootstrapTest passes (3/3 tests, 7 assertions)
- [ ] HTTP 200 on homepage (`curl -sI http://IP/`)
- [ ] `/server-key` returns PGP public key block
- [ ] `/verify` returns HTTP 200
- [ ] Security headers present in response
- [ ] PHP-FPM runs as `appuser` (uid 1000, not root)
- [ ] All containers healthy
- [ ] Swap active (2GB, swappiness=10)
- [ ] UFW active (ports 2222/80/443)
- [ ] Fail2ban running
