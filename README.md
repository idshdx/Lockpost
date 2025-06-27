# SYM.PGP.ONY

[![GitLab](https://img.shields.io/badge/GitLab-Main_Repository-orange.svg)](https://gitlab.com/zer0lis/sym-pgp-ony)

> **Note**: This is a mirror repository. The primary development takes place at [gitlab.com/zer0lis/sym-pgp-ony](https://gitlab.com/zer0lis/sym-pgp-ony)

## Table of Contents

1. [Overview](#overview)
2. [Features](#features)
3. [System Architecture](#system-architecture)
4. [Security](#security)
5. [Technical Implementation](#technical-implementation)
6. [Development Guide](#development-guide)
7. [Future Plans](#future-plans)
8. [Support & Contributing](#support--contributing)

## Overview

SYM.PGP.ONY is a secure web application enabling users to receive PGP-encrypted messages through shareable links. It
solves the challenge of securely receiving sensitive information from users unfamiliar with encryption technologies.

### Core Workflow

1. Sender generates unique link with their PGP email address
2. System verifies public PGP key against key servers
3. Sender shares link with intended recipient
4. Recipient submits message through secure form
5. Message is encrypted in browser using sender's public key
6. Server signs encrypted message and forwards to sender
7. Sender decrypts message using private PGP key
8. Sender can verify server signature for authenticity

## Features

- End-to-End Encryption
- Client-Side Encryption
- Server-Side Message Signing
- Zero Storage Design
- No Tracking or Cookies
- Open Source Software
- Modern Cryptographic Standards

## System Architecture

### Core Components

1. **Token Link Service**
    - Generates secure time-sensitive tokens
    - Uses AES-256-CBC encryption
    - Implements SHA-256 HMAC authentication

2. **PGP Key Service**
    - Verifies keys against trusted servers
    - Provides server failover
    - Supports multiple key servers

3. **PGP Signing Service**
    - Manages server signing keys
    - Handles signature verification
    - Uses GnuPG bindings

### Technical Stack

- Framework: Symfony
- Encryption: OpenPGP.js
- Email: Symfony Mailer
- Development: Docker, NGINX, PHP-FPM
- Testing: PHPUnit, MailHog

## Security

- AES-256-CBC encryption
- SHA-256 HMAC authentication
- Stateless design
- Rate limiting
- Token expiration
- Isolated signing environment
- Regular key rotation

## Technical Implementation

### Configuration

```yaml
app:
    gpg:
        public_key_path: '%kernel.project_dir%/config/pgp/public.key'
        private_key_path: '%kernel.project_dir%/config/pgp/private.key'
```

### Key Management
- Secure key storage
- Permission controls
- Automated rotation
- HSM support (planned)

## Development Guide
### Setup
1. Clone repository
2. Install Docker and Docker Compose
3. Run `docker-compose up -d`
4. Configure key permissions:
``` bash
chmod 600 config/pgp/private.key
chmod 644 config/pgp/public.key
```
### Testing
- Use PHPUnit for business logic
- MailHog for email testing ([http://localhost:8025](http://localhost:8025))
- Verify signatures and encryption

## Possible future Plans
1. HSM Integration
2. Mobile Support
3. Analytics Options
4. Localization
5. Advanced Key Management

## Support & Contributing
- GitHub Issues
- Pull Requests Welcome


### Requirements
- PGP key pair
- Published public key
- Secure private key storage
