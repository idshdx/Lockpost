#!/bin/bash

# Exit on any error
set -e

# Configuration
PGP_CONFIG_DIR="config/pgp"
KEY_CONFIG_DIR="${PGP_CONFIG_DIR}/key-config"
PRIVATE_KEY="${PGP_CONFIG_DIR}/private.key"
PUBLIC_KEY="${PGP_CONFIG_DIR}/public.key"
LOG_FILE="${PGP_CONFIG_DIR}/setup.log"

# Logging function
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "${LOG_FILE}"
}

# Create log file
mkdir -p "$(dirname "${LOG_FILE}")"
touch "${LOG_FILE}"
chmod 600 "${LOG_FILE}"

log "Starting PGP environment setup"

# Create necessary directories
mkdir -p "${KEY_CONFIG_DIR}"

# Set proper permissions for directories
chmod 700 "${PGP_CONFIG_DIR}"
chmod 700 "${KEY_CONFIG_DIR}"

# Check if GPG is installed
if ! command -v gpg &> /dev/null; then
    echo "Error: GPG is not installed. Please install GPG first."
    exit 1
fi

# Generate GPG key pair if it doesn't exist
if [ ! -f "${PRIVATE_KEY}" ] || [ ! -f "${PUBLIC_KEY}" ]; then
    echo "Generating new GPG key pair..."
    
    # Create GPG batch configuration
    cat > /tmp/gpg-batch <<EOF
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
EOF

    # Set GNUPGHOME to our config directory
    export GNUPGHOME="${KEY_CONFIG_DIR}"
    
    # Generate key pair
    gpg --batch --generate-key /tmp/gpg-batch
    
    # Export public and private keys
    KEY_ID=$(gpg --list-secret-keys --keyid-format LONG | grep sec | cut -d'/' -f2 | cut -d' ' -f1)
    gpg --export -a "${KEY_ID}" > "${PUBLIC_KEY}"
    gpg --export-secret-key -a "${KEY_ID}" > "${PRIVATE_KEY}"
    
    # Clean up
    rm /tmp/gpg-batch
    
    echo "GPG key pair generated successfully"
fi

# Set correct permissions for keys
chmod 600 "${PRIVATE_KEY}"
chmod 644 "${PUBLIC_KEY}"

# Verify setup
echo "Verifying PGP setup..."

# Check file permissions
PRIVATE_PERMS=$(stat -f "%OLp" "${PRIVATE_KEY}")
PUBLIC_PERMS=$(stat -f "%OLp" "${PUBLIC_KEY}")
DIR_PERMS=$(stat -f "%OLp" "${KEY_CONFIG_DIR}")

if [ "${PRIVATE_PERMS}" != "600" ]; then
    echo "Error: Incorrect permissions on private key (${PRIVATE_PERMS})"
    exit 1
fi

if [ "${PUBLIC_PERMS}" != "644" ]; then
    echo "Error: Incorrect permissions on public key (${PUBLIC_PERMS})"
    exit 1
fi

if [ "${DIR_PERMS}" != "700" ]; then
    echo "Error: Incorrect permissions on key config directory (${DIR_PERMS})"
    exit 1
fi

# Verify key functionality
echo "Test message" > /tmp/test-message
if ! gpg --homedir "${KEY_CONFIG_DIR}" --sign /tmp/test-message &> /dev/null; then
    echo "Error: Failed to sign test message"
    rm /tmp/test-message
    exit 1
fi

# Clean up test files
rm /tmp/test-message /tmp/test-message.gpg 2>/dev/null || true

log "PGP setup completed successfully!"
log "Private key location: ${PRIVATE_KEY}"

# Run validation script
log "Running PGP environment validation..."
if ! ./scripts/validate-pgp.sh; then
    log "ERROR: PGP environment validation failed"
    exit 1
fi

log "Setup and validation completed successfully!"
echo "Public key location: ${PUBLIC_KEY}"