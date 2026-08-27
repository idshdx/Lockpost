#!/bin/bash
set -e

# Regenerate PGP key with passphrase for production
cd /var/www/app

export GNUPGHOME=/var/www/app/config/pgp/key-config

# Remove old unprotected key files
rm -f /var/www/app/config/pgp/private.key /var/www/app/config/pgp/public.key
rm -rf /var/www/app/config/pgp/key-config/*

# Generate new passphrase-protected key
bash /var/www/app/scripts/init-pgp.sh --with-passphrase your-secure-passphrase

echo "=== Key regenerated with passphrase ==="
