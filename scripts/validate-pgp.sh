#!/bin/bash

# Exit on any error
set -e

# Configuration
PGP_CONFIG_DIR="config/pgp"
KEY_CONFIG_DIR="${PGP_CONFIG_DIR}/key-config"
PRIVATE_KEY="${PGP_CONFIG_DIR}/private.key"
PUBLIC_KEY="${PGP_CONFIG_DIR}/public.key"
LOG_FILE="${PGP_CONFIG_DIR}/validation.log"

# Logging function
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "${LOG_FILE}"
}

# Create log file
touch "${LOG_FILE}"
chmod 600 "${LOG_FILE}"

log "Starting PGP environment validation"

# Check directory structure
for dir in "${PGP_CONFIG_DIR}" "${KEY_CONFIG_DIR}"; do
    if [ ! -d "${dir}" ]; then
        log "ERROR: Directory ${dir} does not exist"
        exit 1
    fi

    dir_perms=$(stat -f "%OLp" "${dir}")
    if [ "${dir_perms}" != "700" ]; then
        log "ERROR: Incorrect permissions on ${dir} (${dir_perms})"
        exit 1
    fi
    log "Directory ${dir} exists with correct permissions"
done

# Check key files
for key_file in "${PRIVATE_KEY}" "${PUBLIC_KEY}"; do
    if [ ! -f "${key_file}" ]; then
        log "ERROR: Key file ${key_file} does not exist"
        exit 1
    fi

    expected_perms="600"
    if [ "${key_file}" = "${PUBLIC_KEY}" ]; then
        expected_perms="644"
    fi

    key_perms=$(stat -f "%OLp" "${key_file}")
    if [ "${key_perms}" != "${expected_perms}" ]; then
        log "ERROR: Incorrect permissions on ${key_file} (${key_perms})"
        exit 1
    fi
    log "Key file ${key_file} exists with correct permissions"
done

# Verify GPG environment
export GNUPGHOME="${KEY_CONFIG_DIR}"
if ! gpg --list-secret-keys &> /dev/null; then
    log "ERROR: No secret keys found in GPG keyring"
    exit 1
fi
log "GPG keyring contains secret keys"

# Test signing functionality
test_message="Test message for PGP signing validation"
echo "${test_message}" > /tmp/test-message

if ! gpg --sign /tmp/test-message &> /dev/null; then
    log "ERROR: Failed to sign test message"
    rm -f /tmp/test-message
    exit 1
fi

# Verify signature
if ! gpg --verify /tmp/test-message.gpg &> /dev/null; then
    log "ERROR: Failed to verify test message signature"
    rm -f /tmp/test-message /tmp/test-message.gpg
    exit 1
fi

# Clean up test files
rm -f /tmp/test-message /tmp/test-message.gpg

log "PGP environment validation completed successfully"

# Check PHP GnuPG extension
if ! docker exec -it php php -m | grep -q "gnupg"; then
    log "ERROR: PHP GnuPG extension is not installed"
    exit 1
fi
log "PHP GnuPG extension is installed"

log "All validation checks passed successfully!"
exit 0
