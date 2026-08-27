#!/bin/bash
# Fix permissions on the VPS after PGP key regeneration
cd /opt/lockpost
sudo chown -R www-data:www-data config/pgp/ var/
docker compose -f docker-compose.prod.yml restart php nginx
echo "=== Permissions fixed and services restarted ==="
docker compose -f docker-compose.prod.yml ps
