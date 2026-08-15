# Lockpost — Production Deployment Runbook

This document covers every step required to bring up the Lockpost application on a fresh Oracle Cloud VPS, from OS prerequisites to a running, smoke-tested production stack.

---

## Prerequisites

Before starting, ensure the following are available on the VPS:

- **Docker Engine 24+** — [installation guide](https://docs.docker.com/engine/install/ubuntu/)
- **Docker Compose v2** — included with Docker Desktop; on a server install via `apt-get install docker-compose-plugin`
- **Git**
- **Port 80 open** — both in the OS firewall (iptables) and in Oracle Cloud's Security List (see Section 1)

> **Oracle Cloud firewall:** Oracle Cloud VPS instances block inbound traffic by default at the cloud level. You must open port 80 in both iptables *and* the Oracle Cloud Security List. Both steps are covered in Section 1.

---

## 1. Server Preparation

### 1.1 Install Docker Engine

Follow the official Docker installation guide for Ubuntu:
[https://docs.docker.com/engine/install/ubuntu/](https://docs.docker.com/engine/install/ubuntu/)

Quick summary:

```shell
sudo apt-get update
sudo apt-get install -y ca-certificates curl gnupg
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

Verify:

```shell
docker --version          # Docker version 24.x or later
docker compose version    # Docker Compose version v2.x
```

### 1.2 Open Port 80

**Step A — iptables (OS-level):**

```shell
sudo iptables -I INPUT -p tcp --dport 80 -j ACCEPT
# Persist the rule across reboots (Ubuntu/Debian):
sudo apt-get install -y iptables-persistent
sudo netfilter-persistent save
```

**Step B — Oracle Cloud Security List (cloud-level):**

1. Log in to the Oracle Cloud Console.
2. Navigate to **Networking → Virtual Cloud Networks**.
3. Select your VCN, then **Security Lists**.
4. Open the Security List attached to the subnet of your VPS.
5. Under **Ingress Rules**, click **Add Ingress Rules** and enter:
   - Source CIDR: `0.0.0.0/0`
   - IP Protocol: `TCP`
   - Destination Port Range: `80`
6. Save.

> Without both steps, HTTP requests from the internet will not reach the NGINX container.

### 1.3 Clone the Repository

```shell
git clone <repo-url> /opt/lockpost
cd /opt/lockpost
```

Replace `<repo-url>` with the actual Git remote URL (e.g., `https://github.com/youruser/lockpost.git`).

---

## 2. Environment Configuration

### 2.1 Create `.env.prod` from the Template

```shell
cp .env.production.example .env.prod
```

### 2.2 Fill in Required Variables

Open `.env.prod` in a text editor and set each value:

**`APP_SECRET`** — generate a cryptographically random value:

```shell
openssl rand -hex 32
```

Paste the output as the value of `APP_SECRET` in `.env.prod`.

**`APP_MAIL_FROM`** — set to a real sender address at your domain:

```
APP_MAIL_FROM=noreply@yourdomain.com
```

**`PGP_PRIVATE_KEY_PASSPHRASE`** — leave blank if the PGP key was generated with the default `%no-protection` flag (the default behaviour of `scripts/init-pgp.sh`):

```
PGP_PRIVATE_KEY_PASSPHRASE=
```

**`MAILER_DSN`** — with `network_mode: host`, the PHP container shares the VPS host network, so Postfix is reachable at `127.0.0.1`:

```
MAILER_DSN=smtp://127.0.0.1:25
```

> **Note:** If you are using bridge networking instead of host networking, confirm the Docker bridge gateway IP with:
> ```shell
> docker network inspect bridge --format '{{range .IPAM.Config}}{{.Gateway}}{{end}}'
> ```
> Typical value is `172.17.0.1`. Update `MAILER_DSN` accordingly.

---

> **⚠ Security warning:** `.env.prod` contains `APP_SECRET` and other sensitive values. It **must never be committed to the repository**. The file is listed in `.gitignore` — verify this before any `git add` operation. Always create `.env.prod` manually on the VPS from the `.env.production.example` template.

---

## 3. Postfix Setup

Outbound email is delivered via a Postfix instance running on the VPS host (not inside a container). Because both Docker services use `network_mode: host`, the PHP container shares the VPS network namespace and reaches Postfix at `127.0.0.1:25`.

### 3.1 Install Postfix

```shell
sudo apt-get update
sudo apt-get install -y postfix
```

During the interactive prompt, select **Internet Site** and enter your domain name. You can reconfigure later.

### 3.2 Configure `main.cf`

Edit `/etc/postfix/main.cf`:

```shell
sudo nano /etc/postfix/main.cf
```

Set (or add) the following lines:

```
inet_interfaces = loopback-only
mynetworks = 127.0.0.0/8
```

- `inet_interfaces = loopback-only` — Postfix listens only on `127.0.0.1`. With `network_mode: host`, this is sufficient for the PHP container to reach Postfix.
- `mynetworks = 127.0.0.0/8` — allows relay from the loopback interface without authentication.

### 3.3 Restart and Enable Postfix

```shell
sudo systemctl restart postfix
sudo systemctl enable postfix
```

### 3.4 Verify SMTP Connectivity

```shell
telnet 127.0.0.1 25
```

You should see a banner like:

```
220 yourhostname ESMTP Postfix (Ubuntu)
```

Type `QUIT` to exit. If the connection is refused, check `sudo systemctl status postfix` for errors.

---

## 4. PGP Key Generation

The server PGP key pair is stored in `config/pgp/` on the VPS host and mounted into the PHP container. Key files are gitignored and must be generated on the VPS — never committed to the repository.

### 4.1 Bring Up the PHP Container

```shell
docker compose -f docker-compose.prod.yml up -d php
```

### 4.2 Generate the Key Pair

```shell
docker exec php bash /var/www/app/scripts/init-pgp.sh
```

This creates `config/pgp/private.key` and `config/pgp/public.key` on the host (via the bind mount).

### 4.3 Fix Ownership

```shell
docker exec php bash -c "chown -R www-data:www-data /var/www/app/config/pgp/"
```

### 4.4 Verify Permissions

```shell
docker exec php bash -c "ls -la /var/www/app/config/pgp/"
```

Expected output:

```
drwx------  ... config/pgp/        (700 — directory)
-rw-------  ... private.key        (600 — owner read/write only)
-rw-r--r--  ... public.key         (644 — world-readable)
drwx------  ... key-config/        (700 — GnuPG keyring directory)
```

If permissions are wrong, fix them manually:

```shell
docker exec php bash -c "chmod 700 /var/www/app/config/pgp/ /var/www/app/config/pgp/key-config"
docker exec php bash -c "chmod 600 /var/www/app/config/pgp/private.key"
docker exec php bash -c "chmod 644 /var/www/app/config/pgp/public.key"
```

### 4.5 Run the Validation Script

```shell
docker exec php bash /var/www/app/scripts/validate-pgp.sh
```

This confirms that GnuPG can load the private key and that signing works end-to-end. If the script reports errors, resolve them before proceeding.

> **Note:** `config/pgp/` is listed in `.gitignore`. Key files must never be committed to the repository.

---

## 5. Build and Start

### 5.1 Build Images

```shell
docker compose -f docker-compose.prod.yml build
```

This builds both the `nginx` image (with `APP_ENV=prod`, which selects `upstream-prod.conf` targeting `localhost:9000`) and the `php` image.

> **Xdebug note:** The production compose file does not mount `docker/php/conf.d/xdebug.ini`. The Xdebug extension is still compiled into the image but loads with no active mode, making it effectively inactive. No performance or security impact from Xdebug in production.

### 5.2 Start All Services

```shell
docker compose -f docker-compose.prod.yml up -d
```

Both the `nginx` and `php` containers will start with `restart: unless-stopped`, so they come back automatically after a VPS reboot.

---

## 6. Symfony Cache Warmup

After the containers are running, pre-compile the Symfony DI container and route cache so the first requests are served from the optimised cache:

```shell
docker exec php php bin/console cache:clear --env=prod --no-debug
docker exec php php bin/console cache:warmup --env=prod --no-debug
docker exec php php bin/console asset-map:compile
docker exec php chown -R www-data:www-data /var/www/app/var/
```

The final `chown` ensures PHP-FPM (running as `www-data`) can read and write the compiled cache. This step must be repeated after every redeployment.

---

## 7. Smoke Tests

Run these checks after initial deployment (and after every redeployment) to confirm the stack is healthy.

### 7.1 NGINX Responds on Port 80

```shell
curl -s -o /dev/null -w "%{http_code}" http://localhost/
```

Expected output: `200`

### 7.2 PHP-FPM is Alive

```shell
docker exec php php -r "echo 'ok';"
```

Expected output: `ok`

### 7.3 Verify from an External Client

From a machine outside the VPS, replace `<VPS_PUBLIC_IP>` with your server's public IP:

```shell
curl http://<VPS_PUBLIC_IP>/
```

Expected: the Lockpost homepage HTML. If you get a connection error, check the Oracle Cloud Security List (port 80) and iptables rules (Section 1.2).

### 7.4 Check Container Status

```shell
docker compose -f docker-compose.prod.yml ps
```

Both `nginx` and `php` services should show `running`.

### 7.5 Check Application Logs

```shell
docker exec php tail -f /var/www/app/var/log/prod.log
```

There should be no `ERROR` or `CRITICAL` entries on startup. Exit with `Ctrl+C`.

---

## 8. Redeployment

Use this procedure to deploy updated code to the VPS. No data is lost: `config/pgp/` and `.env.prod` live on the host filesystem and are not touched by image rebuilds or container restarts.

### 8.1 Pull Updated Code

```shell
cd /opt/lockpost
git pull
```

### 8.2 Rebuild Images

```shell
docker compose -f docker-compose.prod.yml build
```

### 8.3 Restart Services

```shell
docker compose -f docker-compose.prod.yml up -d
```

Docker Compose will recreate only the containers whose image or configuration changed.

### 8.4 Re-warm the Cache

Repeat the cache warmup from Section 6:

```shell
docker exec php php bin/console cache:clear --env=prod --no-debug
docker exec php php bin/console cache:warmup --env=prod --no-debug
docker exec php php bin/console asset-map:compile
docker exec php chown -R www-data:www-data /var/www/app/var/
```

### 8.5 Re-run Smoke Tests

Repeat Section 7 to confirm the updated deployment is healthy.

> **Persistence note:** `config/pgp/` and `.env.prod` are bind-mounted from the VPS host filesystem. They survive image rebuilds, `docker compose down`, and VPS reboots. You do not need to regenerate PGP keys or recreate `.env.prod` during a normal redeployment.
