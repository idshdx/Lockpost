#!/bin/bash
set -e

# Usage: ./init-pgp.sh [--with-passphrase <PASSPHRASE>]
#   --with-passphrase : Generate the key WITH the given passphrase (required for prod).
#                       If omitted, key is generated with %no-protection (dev convenience).

PASSPHRASE=""
if [ "$1" = "--with-passphrase" ] && [ -n "$2" ]; then
    PASSPHRASE="$2"
    shift 2
fi

PGP_CONFIG_DIR="/var/www/app/config/pgp"
KEY_CONFIG_DIR="${PGP_CONFIG_DIR}/key-config"
PRIVATE_KEY="${PGP_CONFIG_DIR}/private.key"
PUBLIC_KEY="${PGP_CONFIG_DIR}/public.key"

mkdir -p "${KEY_CONFIG_DIR}" "${PGP_CONFIG_DIR}/private-keys-v1.d" "${PGP_CONFIG_DIR}/openpgp-revocs.d"
chmod -R 700 "${PGP_CONFIG_DIR}"

export GNUPGHOME="${KEY_CONFIG_DIR}"

if [ -f "${PRIVATE_KEY}" ] && [ -f "${PUBLIC_KEY}" ]; then
  echo "Existing key pair found, skipping generation."
else
  echo "Generating new GPG key pair..."

  cat > /tmp/gpg-batch <<EOF
%echo Generating GPG key
Key-Type: RSA
Key-Length: 4096
Subkey-Type: RSA
Subkey-Length: 4096
Name-Real: PGP Reply Server
Name-Email: server@pgpreply.local
Expire-Date: 0
$(if [ -n "${PASSPHRASE}" ]; then echo "Passphrase: ${PASSPHRASE}"; else echo "%no-protection"; fi)
%commit
%echo Done
EOF

  gpg --batch --generate-key /tmp/gpg-batch
  rm /tmp/gpg-batch
  KEY_ID=$(gpg --list-secret-keys --keyid-format LONG | grep sec | head -1 | cut -d'/' -f2 | cut -d' ' -f1)
  echo "Key ID: ${KEY_ID}"
  gpg --export -a "${KEY_ID}" > "${PUBLIC_KEY}"
  gpg --export-secret-key -a "${KEY_ID}" > "${PRIVATE_KEY}"
  echo "Key pair generated successfully."
fi

chmod 600 "${PRIVATE_KEY}"
chmod 644 "${PUBLIC_KEY}"

cat > "${KEY_CONFIG_DIR}/gpg.conf" <<'EOF'
use-agent
pinentry-mode loopback
no-emit-version
no-comments
export-options export-minimal
EOF

echo "PGP setup complete."
echo "--- config/pgp contents ---"
ls -la "${PGP_CONFIG_DIR}"
