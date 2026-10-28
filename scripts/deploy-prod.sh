#!/bin/bash
set -e

APP_DIR="/home/ubuntu/lockpost"
cd "$APP_DIR"

echo "=== Step 1: Create config/pgp directory ==="
mkdir -p config/pgp
chmod 700 config/pgp

echo "=== Step 2: Build Docker images (php:final, nginx:prod) ==="
docker compose -f docker-compose.prod.yml build --no-cache php nginx 2>&1
echo "Build complete."

echo "=== Step 3: Start containers ==="
docker compose -f docker-compose.prod.yml up -d
echo "Waiting 10s for containers to boot..."
sleep 10

echo "=== Step 4: Install Composer dependencies ==="
docker compose -f docker-compose.prod.yml exec -T php composer install --no-scripts 2>&1 | tail -5
echo "Composer install complete."

echo "=== Step 5: Generate PGP keys with production passphrase ==="
docker compose -f docker-compose.prod.yml exec -T php bash -c '
    set -a
    . /var/www/app/.env
    set +a
    if [ -z "$PGP_PRIVATE_KEY_PASSPHRASE" ]; then
        echo "ERROR: PGP_PRIVATE_KEY_PASSPHRASE not set in .env"
        exit 1
    fi
    echo "Passphrase is set. Generating PGP key..."
    bash /var/www/app/scripts/init-pgp.sh --with-passphrase "$PGP_PRIVATE_KEY_PASSPHRASE"
'
echo "PGP key generation complete."

echo "=== Step 6: Fix key permissions ==="
docker compose -f docker-compose.prod.yml exec -T php bash -c '
    chmod 600 /var/www/app/config/pgp/private.key
    chmod 644 /var/www/app/config/pgp/public.key
    chmod 700 /var/www/app/config/pgp/key-config
    chown -R www-data:www-data /var/www/app/config/pgp/ /var/www/app/var/
    echo "Permissions fixed:"
    ls -la /var/www/app/config/pgp/private.key /var/www/app/config/pgp/public.key
'
echo "Permissions fixed."

echo "=== Step 7: Clear and warm up cache ==="
docker compose -f docker-compose.prod.yml exec -T php bash -c '
    export APP_ENV=prod
    export APP_DEBUG=0
    php bin/console cache:clear --no-debug 2>&1
    php bin/console cache:warmup --no-debug 2>&1
    php bin/console asset-map:compile 2>/dev/null || true
    chown -R www-data:www-data /var/www/app/var/ /var/www/app/config/pgp/
'
echo "Cache warmed up."

echo "=== Step 8: Restart containers ==="
docker compose -f docker-compose.prod.yml restart php nginx
sleep 5

echo "=== Step 9: Run smoke test (BootstrapTest) ==="
docker compose -f docker-compose.prod.yml exec -T php bash -c '
    export APP_ENV=test
    export APP_DEBUG=1
    php vendor/bin/phpunit tests/BootstrapTest.php --no-coverage
' 2>&1

echo "=== Step 10: Verify HTTP endpoints ==="
echo -n "Homepage:    " && curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:80/ && echo ""
echo -n "About:       " && curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:80/about && echo ""
echo -n "Privacy:     " && curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:80/privacy && echo ""
echo -n "Server Key:  " && curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:80/server-key && echo ""
echo -n "Verify:      " && curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:80/verify && echo ""

echo "=== Deployment Summary ==="
docker compose -f docker-compose.prod.yml ps
echo "=== Deployment Complete ==="
