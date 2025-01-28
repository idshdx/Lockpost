#!/bin/bash

# Exit on any error
set -e

# Configuration
TMP_DIR="/tmp/pgp-verify"
MESSAGE_FILE="${TMP_DIR}/message.asc"
SIGNATURE_FILE="${TMP_DIR}/signature.asc"
SERVER_PUBLIC_KEY="config/pgp/public.key"

# Create temporary directory with secure permissions
mkdir -p "${TMP_DIR}"
chmod 700 "${TMP_DIR}"

# Logging function
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

# Cleanup function
cleanup() {
    log "Cleaning up temporary files..."
    rm -rf "${TMP_DIR}"
}

# Set cleanup to run on script exit
trap cleanup EXIT

# Function to split message and signature
split_message() {
    local input_file="$1"

    # Extract PGP message
    awk '/-----BEGIN PGP MESSAGE-----/,/-----END PGP MESSAGE-----/' "$input_file" >"${MESSAGE_FILE}"

    # Extract PGP signature
    awk '/-----BEGIN PGP SIGNATURE-----/,/-----END PGP SIGNATURE-----/' "$input_file" >"${SIGNATURE_FILE}"

    # Verify both parts were extracted
    if [ ! -s "${MESSAGE_FILE}" ] || [ ! -s "${SIGNATURE_FILE}" ]; then
        log "ERROR: Failed to extract message or signature"
        exit 1
    fi
}

# Function to verifySignaturePage signature
verify_signature() {
    log "Verifying message signature..."

    # Import server's public key if not already imported
    if ! gpg --list-keys "server@pgpreply.local" &>/dev/null; then
        log "Importing server's public key..."
        gpg --import "${SERVER_PUBLIC_KEY}" || {
            log "ERROR: Failed to import server's public key"
            exit 1
        }
    fi

    # Verify the signature
    if gpg --verifySignature "${SIGNATURE_FILE}" "${MESSAGE_FILE}"; then
        log "Signature verification successful!"
        return 0
    else
        log "ERROR: Signature verification failed!"
        return 1
    fi
}

# Function to decrypt message
decrypt_message() {
    log "Decrypting message..."
    gpg --decrypt "${MESSAGE_FILE}"
}

# Main execution
if [ "$#" -ne 1 ]; then
    echo "Usage: $0 <input_file>"
    exit 1
fi

INPUT_FILE="$1"

if [ ! -f "${INPUT_FILE}" ]; then
    log "ERROR: Input file does not exist"
    exit 1
    }

    # Process the message
    log "Processing message from ${INPUT_FILE}"
    split_message "${INPUT_FILE}"

    # Verify and decrypt
    if verify_signature; then
log "Proceeding with decryption..."
decrypt_message
fi
