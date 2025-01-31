#!/bin/bash
# Exit on any error
set -e

# Configuration
CONTAINER_NAME="php"
PGP_CONFIG_DIR="/var/www/app/config/pgp"
KEY_CONFIG_DIR="${PGP_CONFIG_DIR}/key-config"
PRIVATE_KEY="${PGP_CONFIG_DIR}/private.key"
PUBLIC_KEY="${PGP_CONFIG_DIR}/public.key"
LOG_FILE="${PGP_CONFIG_DIR}/setup.log"

# Execute commands in Docker container
docker exec -i ${CONTAINER_NAME} bash <<EOF
set -e

# Ensure we're running as www-data
if [ "\$(id -u)" = "0" ]; then
    exec su -s /bin/bash www-data -c '\$0 "\$@"'
    exit 0
fi

# Create log file
mkdir -p "\$(dirname "${LOG_FILE}")"
touch "${LOG_FILE}"
chmod 600 "${LOG_FILE}"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting PGP environment setup in Docker container" | tee -a "${LOG_FILE}"

# Create necessary directories
mkdir -p "${KEY_CONFIG_DIR}"
mkdir -p "${PGP_CONFIG_DIR}/private-keys-v1.d"
mkdir -p "${PGP_CONFIG_DIR}/openpgp-revocs.d"

# Set proper permissions
chmod -R 700 "${PGP_CONFIG_DIR}"

# Check if GPG is installed
if ! command -v gpg &> /dev/null; then
    echo "Error: GPG is not installed in the container."
    exit 1
fi

# Check for existing GPG key pair
if [ -f "${PRIVATE_KEY}" ] && [ -f "${PUBLIC_KEY}" ]; then
    echo "Existing GPG key pair found. Skipping key generation." | tee -a "${LOG_FILE}"
else
    echo "No existing GPG key pair found. Generating a new one..." | tee -a "${LOG_FILE}"

    # Create GPG batch configuration
    cat > /tmp/gpg-batch <<EOB
%echo Generating GPG key
Key-Type: RSA
Key-Length: 4096
Subkey-Type: RSA
Subkey-Length: 4096
Name-Real: PGP Reply Server
Name-Email: server@pgpreply.local
Expire-Date: 0
%no-protection
%commit
%echo Done
EOB

    # Set GNUPGHOME to our config directory
    export GNUPGHOME="${KEY_CONFIG_DIR}"

    # Generate key pair
    gpg --batch --generate-key /tmp/gpg-batch

    # Export public and private keys
    KEY_ID=\$(gpg --list-secret-keys --keyid-format LONG | grep sec | cut -d'/' -f2 | cut -d' ' -f1)
    gpg --export -a "\${KEY_ID}" > "${PUBLIC_KEY}"
    gpg --export-secret-key -a "\${KEY_ID}" > "${PRIVATE_KEY}"

    # Clean up
    rm /tmp/gpg-batch

    echo "GPG key pair generated successfully." | tee -a "${LOG_FILE}"
fi

# Set correct permissions for keys
chmod 600 "${PRIVATE_KEY}"
chmod 644 "${PUBLIC_KEY}"

# Configure GPG
cat > "${KEY_CONFIG_DIR}/gpg.conf" <<EOC
use-agent
pinentry-mode loopback
no-emit-version
no-comments
export-options export-minimal
EOC

# Verify setup
echo "Verifying PGP setup..." | tee -a "${LOG_FILE}"

# Check file permissions
PRIVATE_PERMS=\$(stat -c "%a" "${PRIVATE_KEY}")
PUBLIC_PERMS=\$(stat -c "%a" "${PUBLIC_KEY}")
DIR_PERMS=\$(stat -c "%a" "${KEY_CONFIG_DIR}")

if [ "\${PRIVATE_PERMS}" != "600" ]; then
    echo "Error: Incorrect permissions on private key (\${PRIVATE_PERMS})."
    exit 1
fi

if [ "\${PUBLIC_PERMS}" != "644" ]; then
    echo "Error: Incorrect permissions on public key (\${PUBLIC_PERMS})."
    exit 1
fi

if [ "\${DIR_PERMS}" != "700" ]; then
    echo "Error: Incorrect permissions on key config directory (\${DIR_PERMS})."
    exit 1
fi

echo "PGP setup completed successfully!" | tee -a "${LOG_FILE}"
EOF
