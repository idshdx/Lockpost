---
name: deploy-salt-ssh
domain: prod-server
title: Production Deployment via Salt-SSH
description: Deploying updated code to the Oracle VPS Lockpost server using salt-ssh.
---

# Production Deployment via Salt-SSH

**Use when:** Deploying updated Lockpost code to the production Oracle VPS (129.159.7.42).

## Overview

The project includes a Salt state file at `salt/states/lockpost.sls` that performs a full deployment: code pull, image rebuild, cache warmup, smoke tests, and verification. The salt roster at `salt/roster` defines the target as `lockpost-vps`.

## Prerequisites

- SSH key `~/.ssh/ssh-key-oracle.key` (already set up on the VPS)
- SSH access to the VPS confirmed (see `connect-ssh.md`)
- Local machine has `salt-ssh` installed (NOT on the VPS itself — the VPS has salt-master but salt-ssh is not installed there)

### Install salt-ssh locally (if not already installed)

On the local development machine (Qubes OS Debian 13 VM):

```bash
# Option 1: pipx (recommended)
pipx install salt-ssh

# Option 2: pip
pip3 install salt-ssh
```

## Deployment Process

### 1. Commit and push changes to Git

```bash
git add -A
git commit -m "your changes"
git push origin main
```

### 2. Run the Salt Deployment

From the project root on your local machine:

```bash
salt-ssh -i ~/.ssh/ssh-key-oracle.key -c salt/ lockpost-vps state.apply lockpost
```

This runs the `lockpost.sls` state which:
1. **Pulls latest code**: `git fetch origin && git reset --hard origin/main`
2. **Fixes CRLF line endings**: `find docker/ -type f -exec sed -i 's/\r$//' {} +`
3. **Pulls and builds images**: `docker compose -f docker-compose.prod.yml pull && docker compose -f docker-compose.prod.yml build --no-cache php nginx`
4. **Restarts services**: `docker compose -f docker-compose.prod.yml down --remove-orphans` then `up -d --force-recreate`
5. **Clears cache**: `rm -rf /var/www/app/var/cache/prod/* /var/www/app/var/cache/test/*`
6. **Warms cache**: `APP_ENV=prod php bin/console cache:clear && cache:warmup`
7. **Fixes permissions**: `chown -R appuser:appgroup var/`
8. **Tests**: Runs bootstrap test + curls HTTP endpoints
9. **Verifies**: Prints container status and HTTP status codes

### 3. Alternative: Manual SSH Deployment

If salt-ssh is not available, deploy manually:

```bash
# SSH into the VPS
ssh -i ~/.ssh/ssh-key-oracle.key ubuntu@129.159.7.42

# On the VPS:
cd /opt/lockpost
git fetch origin
git reset --hard origin/main
find docker/ -type f -exec sed -i 's/\r$//' {} +
docker compose -f docker-compose.prod.yml build --no-cache php nginx
docker compose -f docker-compose.prod.yml down --remove-orphans
docker compose -f docker-compose.prod.yml up -d --force-recreate
docker compose -f docker-compose.prod.yml exec php sh -c 'rm -rf /var/www/app/var/cache/prod/* /var/www/app/var/cache/test/*'
docker compose -f docker-compose.prod.yml exec php sh -c 'export APP_ENV=prod APP_DEBUG=0; php bin/console cache:clear; php bin/console cache:warmup; chown -R appuser:appgroup var/'
docker compose -f docker-compose.prod.yml restart php nginx
```

## Smoke Tests

After deployment, verify the stack:

```bash
# Via salt-ssh
salt-ssh -i ~/.ssh/ssh-key-oracle.key -c salt/ lockpost-vps cmd.run 'cd /opt/lockpost && docker compose -f docker-compose.prod.yml ps && curl -sI http://127.0.0.1:80/'

# Or manually:
ssh -i ~/.ssh/ssh-key-oracle.key ubuntu@129.159.7.42
cd /opt/lockpost && docker compose -f docker-compose.prod.yml ps
curl -sI http://127.0.0.1:80/
curl -sI http://127.0.0.1:80/verify
curl -sI http://127.0.0.1:80/server-key
```

## Troubleshooting

- **salt-ssh "command not found"**: Install salt-ssh locally — it is NOT installed on the VPS. See Prerequisites.
- **SSH permission denied**: Ensure `~/.ssh/ssh-key-oracle.key` has `600` permissions: `chmod 600 ~/.ssh/ssh-key-oracle.key`
- **Docker compose file not found**: The app is at `/opt/lockpost/` on the VPS. Ensure `docker-compose.prod.yml` exists.
- **PGP key passphrase**: The prod `.env` has `PGP_PRIVATE_KEY_PASSPHRASE` set. Keys in `config/pgp/` persist across deployments (bind-mounted from host).
- **CRLF errors**: The salt state automatically runs `sed -i 's/\r$//'` on Docker files. If deploying manually, do this step.
- **Cache permission errors**: The salt state sets `chown -R appuser:appgroup var/`. Adjust the user/group if your VPS uses a different setup.
- **Port 80 not responding**: Ensure port 80/443 are open in the Oracle Cloud Security List AND in ufw on the VPS (see `docs/deployment.md` Section 1.2).
