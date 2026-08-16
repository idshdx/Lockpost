# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security
- Closed open email relay on `/message/submit` by removing the token-bypass fallback
- Added CSRF protection to the JSON message submission endpoint
- Added rate limiting on `/message/submit` with per-IP and per-token limits
- Isolated GnuPG verification into a temporary keyring per request to prevent keyring pollution and `putenv()` races
- Enforced non-empty `PGP_PRIVATE_KEY_PASSPHRASE` in production and test environments

### Fixed
- Rebuilt email template as a single valid HTML document using an inline-CSS table layout
- Removed JavaScript and `onclick` handlers from the email template for mail-client compatibility
- Fixed sign/verify API mode mismatch in `PgpSigningService` by verifying combined cleartext-signed blocks correctly
- Applied HKDF key derivation with domain separation for token encryption and authentication keys
- Aligned `GNUPGHOME` path between Docker Compose and Symfony service configuration
- Renamed Composer platform requirement from `ext-zend-opcache` to `ext-opcache`
- Reduced nginx `client_max_body_size` from `512M` to `1M`
- Switched production Compose to bridge networking and removed `network_mode: host`
- Replaced PHP-FPM host port mappings with internal `expose` only
- Deleted dead `config/packages/gpg.yaml` config file
- Removed unused `TransportExceptionInterface` import from `DefaultController`
- Fixed test method naming typo `testInvalidSighing` to `testInvalidSigning`
- Set explicit `cookie_samesite: lax` in framework session configuration

### Changed
- Cached initialized GnuPG signer instance in `PgpSigningService` constructor
- Rewrote exception wrapping to preserve `$previous` for debugging
- Promoted `ErrorHandler` to a constructor-promoted readonly property in `DefaultController`

### Added
- New `TokenLinkServiceTest` covering roundtrip validation, tamper detection, expiry, garbage input, short tokens, empty input, whitespace normalization, and token uniqueness
- Server-side verify form now accepts both cleartext-signed and encrypted PGP blocks
- Client-side submit controller sends CSRF token with JSON payload
