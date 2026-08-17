# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security
- Closed open email relay on `/message/submit` by removing the token-bypass fallback
|- Added CSRF protection to the JSON message submission endpoint
|- Added rate limiting on `/message/submit` with per-IP and per-token limits
|- Isolated GnuPG verification into a temporary keyring per request to prevent keyring pollution and `putenv()` races
|- Enforced non-empty `PGP_PRIVATE_KEY_PASSPHRASE` in production and test environments
|- Added UID/email verification for PGP public keys — keys are rejected if the UID doesn't match the requested email, preventing key substitution attacks
|- Moved DTO validation before token-specific rate limiter consumption — malformed requests no longer deplete limiter buckets
|- Added IP-based rate limiting for link generation (key lookups) to prevent unlimited outbound network requests
|- Reduced default token link expiration from 30 days to 7 days; made configurable via `APP_TOKEN_TTL` environment variable
|- Added trusted proxy configuration (`TRUSTED_PROXIES`, `TRUSTED_HOSTS`) for correct absolute URL generation behind reverse proxies
|- Made email verify URLs absolute using `UrlGeneratorInterface::ABSOLUTE_URL`

### Fixed
- Rebuilt email template as a single valid HTML document using an inline-CSS table layout
- Removed JavaScript and `onclick` handlers from the email template for mail-client compatibility
- Fixed sign/verify API mode mismatch in `PgpSplittingService` by verifying combined cleartext-signed blocks correctly
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

|### Changed
|- Upgraded PHP from 8.3 to 8.4 in Docker images (performance and security improvements)
|- Upgraded PHPUnit from 9.6 to 11.5 (latest stable for PHP 8.4)
|- Updated `phpunit.xml.dist` for PHPUnit 11 compatibility (removed deprecated `listeners`/`coverage` elements, added `source` element)
||- Cached initialized GnuPG signer instance in `PgpSigningService` constructor
- Rewrote exception wrapping to preserve `$previous` for debugging
- Promoted `ErrorHandler` to a constructor-promoted readonly property in `DefaultController`
- Rewrote `PgpKeyService` to use UID/email verification before accepting keys from key servers
- `DefaultController::generateLinkResponse()` now accepts `Request` for IP-based rate limiting
- Changed default token link expiration from 30 days to 7 days

|### Added
| |- AEAD token format v2 using XChaCha20-Poly1305 IETF (combined encryption + authentication, 24-byte nonce)
| |- Backward-compatible v1 token validation (AES-256-CBC + HMAC-SHA256) for migration
| |- `generateLinkV1()` method for generating legacy v1 tokens (testing/migration)
| |- `AppSecretValidationPass` compiler pass — enforces minimum 32-character `APP_SECRET` in production
| |- `TokenStateService` for optional stateful link mode (revocation, one-time-use, max submissions)
| |- `APP_TOKEN_STATEFUL` environment variable to toggle stateful mode (default: `0`/disabled)
| |- Clear plaintext message from textarea after client-side encryption
||- Sanitized encryption error messages (no key fingerprint or internal detail leaks)
||- New `TokenLinkServiceTest` covering roundtrip validation, tamper detection, expiry, garbage input, short tokens, empty input, whitespace normalization, and token uniqueness
- Server-side verify form now accepts both cleartext-signed and encrypted PGP blocks
- Client-side submit controller sends CSRF token with JSON payload
- New `PgpKeyServiceTrustTest` with 8 tests covering UID verification, mismatched UID rejection, fingerprint/source tracking, and email normalization
|- New `TokenScrubbingProcessor` Monolog processor for redacting token URLs and email addresses from log entries
||- `TokenLinkService::getExpirationPeriod()` method for exposing configured TTL
|- New `PgpKeyResult` DTO carrying key metadata (fingerprint, source, verified emails)
|- `link.html.twig` template now displays key fingerprint, source, and email UIDs
|- Configurable token expiration via `APP_TOKEN_TTL` environment variable
|- `link_generation` and `link_generation_failed` rate limiters for keyserver lookups
|- `prod/framework.yaml` with trusted proxy configuration for reverse proxy deployments
|- Added `Permissions-Policy` header to NGINX configs (dev and hardened)
|- Set `Strict-Transport-Security` to `max-age=31536000; includeSubDomains; preload` in hardened NGINX config
|- Changed `Referrer-Policy` from `strict-origin-when-cross-origin` to `no-referrer` in hardened NGINX config
|- Added `Cache-Control: no-store` to `/submit/`, `/message/submit`, and `/verify` routes in NGINX configs
|- Added `no-store` `Cache-Control` header to `verifySignaturePage()` response
|- CSP validated for compatibility with Symfony AssetMapper and Stimulus (includes `data:` in script-src)
