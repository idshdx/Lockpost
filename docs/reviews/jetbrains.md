
# Lockpost Codebase Review — WebStorm / JetBrains Review
Date: 2026-08-16  
Reviewer: AI Assistant  
Project: Lockpost / sym-pgp-ony

---

## Executive Summary

Lockpost is a Symfony/PHP security-focused application for receiving PGP-encrypted messages through shareable links. The project has a strong conceptual foundation:

- No database-backed message storage.
- Client-side encryption using OpenPGP.js.
- Stateless encrypted submission tokens.
- Server-side signing of forwarded encrypted messages.
- Basic CSRF protection.
- IP and token-based rate limiting.
- Runtime checks for private key file permissions.
- Isolated temporary GPG home for user-supplied public keys during verification.

The most important risks are not simple syntax or framework issues. They are security-model and production-hardening concerns:

1. PGP signature verification appears too permissive.
2. `GNUPGHOME` is changed via process-global environment mutation.
3. Public key discovery depends partly on weaker keyserver trust models.
4. The README/privacy claims may conflict with the server-side verification endpoint.
5. Stateless links are reusable until expiry and cannot be revoked.
6. Submission tokens in URL paths may leak through proxy/access logs.
7. Email verification links should likely be absolute URLs.
8. DTO validation should happen before token-specific rate limiter consumption.
9. Composer platform checks are disabled.
10. More negative-path/security tests are needed.

Overall, the project is well structured for a small Symfony app, but should receive focused security hardening before production deployment.

---

## Scope Reviewed

Reviewed areas included:

- `composer.json`
- `README.md`
- `src/Controller/DefaultController.php`
- `src/Service/TokenLinkService.php`
- `src/Service/PgpKeyService.php`
- `src/Service/PgpSigningService.php`
- `config/services.yaml`
- Project structure under:
  - `src`
  - `config`
  - `docs`
  - `tests`
  - `docker`
  - `assets`
  - `templates`
- Current uncommitted diff involving `templates/email/message.html.twig`

Some files could not be fully inspected due to tool-call limits, including full framework/rate-limiter configuration, frontend controllers, and Docker production configuration. Recommendations involving those areas should be validated against the actual files.

---

## 1. Architecture Review

### Current Architecture

The app currently follows a compact Symfony service-oriented architecture:
```
text
DefaultController
├─ TokenLinkService
├─ PgpKeyService
├─ PgpSigningService
├─ MailerInterface
├─ CsrfTokenManagerInterface
├─ RateLimiterFactory submit_ip
└─ RateLimiterFactory submit_token
```
The primary application flow is:

1. Recipient enters their email.
2. Server looks up a PGP public key.
3. Server generates a stateless encrypted token containing recipient email and expiration.
4. Sender opens a `/submit/{token}` link.
5. Browser encrypts the message using the recipient public key.
6. Browser POSTs encrypted message to the server.
7. Server validates token.
8. Server signs encrypted message.
9. Server emails the signed encrypted message to the recipient.

### Strengths

- Small application surface area.
- No database dependency.
- No message persistence.
- Good separation of token, key lookup, and signing logic.
- Recipient email is resolved from the token rather than trusted from request payload.
- Server signs ciphertext, not plaintext.
- Symfony services and dependency injection are used appropriately.

### Main Architectural Concern

The “zero persistence” model has important trade-offs:

- Links cannot be revoked.
- Links cannot be single-use.
- Replays are possible until token expiration.
- If `APP_SECRET` leaks, existing token contents can be decrypted and new valid tokens can be forged.
- Abuse prevention depends entirely on rate limiting.

This model can be acceptable, but it should be documented clearly and the default expiration should be conservative.

### Recommendations

- Reduce default link lifetime from 30 days to a shorter period, such as 24 hours or 7 days.
- Document that links are reusable until expiry.
- Consider optional stateful production mode using hashed token nonce records.
- Split `DefaultController` into smaller controllers:
  - `LinkController`
  - `MessageController`
  - `VerificationController`
  - `StaticPageController`

---

## 2. Token Security Review

Reviewed file:
```
text
src/Service/TokenLinkService.php
```
### Positive Findings

The token implementation has several good properties:

- Uses random IV.
- Uses AES-256-CBC encryption.
- Uses HMAC-SHA256 authentication.
- Uses encrypt-then-MAC construction.
- Uses separate HKDF-derived encryption and authentication keys.
- Uses `hash_equals()` for HMAC comparison.
- Includes expiration in the token.
- Normalizes email with lowercase and trim.
- Adds a random nonce.

### Concerns

#### AES-CBC + HMAC Is Correct but More Fragile Than AEAD

The current construction appears sound, but AES-CBC + HMAC requires careful ordering and future maintainers can accidentally weaken it.

A modern AEAD construction would be simpler and safer:

- `aes-256-gcm`
- `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt`

#### `APP_SECRET` Strength Is Critical

The security of all generated links depends on `APP_SECRET`.

If `APP_SECRET` is short, reused, committed, or low entropy, token confidentiality and integrity are weakened.

#### No Token Versioning

The current token format does not appear versioned. This makes future migration to AEAD or new payload formats harder.

### Recommendations

- Enforce minimum `APP_SECRET` length and entropy at startup.
- Add a version prefix, for example:
```
text
v1.<base64url-token>
```
- Consider migrating to AEAD for new tokens.
- Include a token purpose/type field:
```
json
{
"typ": "submit-link",
"email": "recipient@example.com",
"exp": 1234567890,
"nonce": "..."
}
```
- Consider shorter default expiration.

---

## 3. PGP Public Key Discovery Review

Reviewed file:
```
text
src/Service/PgpKeyService.php
```
### Positive Findings

- Email format is validated.
- HTTPS keyservers are used.
- Network timeout is configured.
- Failed responses are skipped.
- Responses are cancelled in a `finally` block.
- PGP armored blocks are extracted.

### Concerns

#### Uneven Trust Model Across Keyservers

The configured servers are:
```
text
https://keys.openpgp.org
https://keyserver.ubuntu.com
https://pgp.mit.edu
```
`keys.openpgp.org` has stronger email-verification semantics. Traditional keyservers may contain stale, poisoned, unverified, or misleading keys.

The current service checks for the presence of:
```
text
BEGIN PGP PUBLIC KEY BLOCK
```
That confirms that a key block exists, but not necessarily that it belongs to the requested email address.

#### No UID/Fingerprint Validation

The service does not appear to parse the returned key and verify that it contains a UID matching the requested email.

#### No Sender Confirmation

The sender appears to receive a submit page with the public key, but the review did not confirm whether the UI displays and asks for confirmation of the key fingerprint.

### Recommendations

- Prefer WKD and `keys.openpgp.org`.
- Consider removing legacy keyserver fallback or marking it as lower-trust.
- Parse returned public keys and confirm matching UID/email.
- Display fingerprint and source to the sender.
- Consider requiring explicit confirmation for keys from less trusted sources.
- Cache key lookup results briefly to reduce latency and outbound abuse.

---

## 4. PGP Signing and Verification Review

Reviewed file:
```
text
src/Service/PgpSigningService.php
```
### Positive Findings

- Private key passphrase is required outside development.
- Private key file must be owner-only accessible.
- GPG error mode uses exceptions.
- Signing key is imported and prepared once.
- Verification uses a temporary isolated GPG home.
- Temporary verification keyring is cleaned up.
- Server public key is served from a configured path.

### High-Severity Concern: Process-Global `GNUPGHOME`

The service changes `GNUPGHOME` using:
```
php
putenv("GNUPGHOME={$this->keyConfigPath}");
```
and during verification:
```
php
putenv("GNUPGHOME={$tmpHome}");
```
Environment variables are process-global. This is brittle and can cause hard-to-debug behavior in:

- PHP-FPM process reuse.
- CLI scripts.
- Messenger workers.
- Tests.
- Future async/concurrent runtimes.

### Recommendation

Avoid process-global `putenv()` for request-specific GPG homes where possible.

Options:

1. Use Symfony Process and call GPG with explicit `--homedir`.
2. Isolate signing and verification into separate processes.
3. Use locking if retaining current extension-based implementation.
4. Confirm whether the installed `gnupg` extension supports an explicit home directory option.

### High-Severity Concern: Verification May Be Too Permissive

The current verification logic appears to accept any signature whose summary does not include `GNUPG_SIGSUM_RED`:
```
php
if (($sig['summary'] & GNUPG_SIGSUM_RED) === 0) {
return true;
}
```
This may accept signatures that are not strictly valid/good, depending on GPG status flags.

### Recommendation

Tighten verification:

- Require explicit good/valid signature status.
- Ensure fingerprint matches the imported public key.
- Reject revoked, expired, bad, or ambiguous signatures.
- Add tests for invalid, expired, revoked, and mismatched signatures.

### Medium Concern: Test Environment Passphrase Requirement

The constructor requires passphrase in both `prod` and `test`:
```
php
if (in_array($appEnv, ['prod', 'test'], true) && $passphrase === '') {
throw new AppException(...)
}
```
This is defensible, but can make tests harder and encourage placeholder passphrases.

### Recommendation

- Require passphrase in `prod`.
- For `test`, use explicit fixture keys and fixture passphrases.

---

## 5. Controller Review

Reviewed file:
```
text
src/Controller/DefaultController.php
```
### Positive Findings

- JSON payload is parsed with `JSON_THROW_ON_ERROR`.
- CSRF token is validated.
- IP rate limiting is applied.
- Token-specific rate limiting is applied.
- Recipient is resolved from the token, not the request body.
- Invalid tokens receive generic errors.
- Internal errors are not exposed to users.
- Message content is not logged.

### Issue: DTO Validation Happens After Token Limiter Consumption

Current flow:

1. Parse JSON.
2. Validate CSRF.
3. Consume IP rate limiter.
4. Create DTO.
5. Validate DTO.
6. Consume token-specific limiter.
7. Return DTO validation errors if any.

This means malformed or empty token values can consume token limiter buckets.

### Recommendation

Change ordering:

1. Parse JSON.
2. Validate CSRF.
3. Consume IP limiter.
4. Create and validate DTO.
5. Validate/decrypt token.
6. Consume token-specific limiter.
7. Sign and send email.

### Issue: `DefaultController` Has Too Many Responsibilities

The controller currently handles:

- Home page.
- Link generation.
- Submit page.
- Message API.
- Verify page.
- Verify POST endpoint.
- About page.
- Privacy page.
- Server public key download.

### Recommendation

Split into focused controllers.

### Issue: Verification Documentation May Be Inaccurate

README says verification runs entirely in the browser, but the controller exposes:
```
text
POST /verify/signature
```
which performs server-side verification.

### Recommendation

Either:

- Remove the server-side verification endpoint if obsolete, or
- Update README/privacy docs to state that server-side verification exists and receives submitted signed messages/public keys.

### Issue: Email Verify URL May Be Relative

The email template receives:
```
php
'app_verify_url' => $this->generateUrl('app_verify'),
```
Symfony `generateUrl()` returns a relative URL by default.

### Recommendation

Use an absolute URL for email links.

---

## 6. Privacy Review

### Positive Findings

- No database-backed message storage is visible.
- Encrypted messages are sent by email and not explicitly persisted.
- Message body is not logged in reviewed controller code.
- Token hides recipient email from casual URL inspection.

### Concerns

#### Token in URL Path

The token appears in:
```
text
/submit/{token}
```
This can leak through:

- Reverse proxy access logs.
- Web server logs.
- Browser history.
- Referrer headers, depending on navigation.
- Chat/link preview tools.
- Monitoring tools.

### Recommendation

- Scrub `/submit/{token}` from logs.
- Add `Referrer-Policy: no-referrer`.
- Consider using URL fragments:
```
text
/submit#token=...
```
with frontend-managed token submission.

#### Server Knows Recipient and Sender Metadata

The app necessarily processes:

- Recipient email.
- Sender IP.
- Submission time.
- Encrypted message size.

Privacy docs should clearly state this.

#### Server-Side Verification Endpoint

If active, the verify POST endpoint receives signed message and public key. This conflicts with “verification runs entirely in browser.”

---

## 7. Email Template Review

Reviewed current uncommitted diff for:
```
text
templates/email/message.html.twig
```
### Positive Findings

The redesigned template removes script-dependent interactive sections. This is good because most email clients block JavaScript.

The new structure is clearer:

1. Encrypted message.
2. Server-signed message.
3. Server public key.

### Concerns

- Dark theme email rendering may vary between clients.
- No plain-text email alternative was observed in the controller.
- Verify URL may be relative.
- Long PGP blocks need careful formatting in email clients.

### Recommendations

- Add a plain-text email body with `.text(...)`.
- Use absolute verification URL.
- Test in Gmail, Outlook, Apple Mail, Thunderbird, and mobile clients.
- Avoid relying entirely on color contrast.
- Confirm line wrapping does not corrupt armored PGP blocks.

---

## 8. Dependency Review

Reviewed file:
```
text
composer.json
```
### Positive Findings

- PHP 8.3 platform is configured.
- Symfony components are modern.
- Required extensions are declared.
- Dependencies are sorted.

### Concerns

#### Composer Platform Check Disabled

`composer.json` contains:
```
json
"platform-check": false
```
This can hide missing extensions at runtime.

### Recommendation

Enable Composer platform checks unless there is a strong container-specific reason to disable them.

#### README Symfony Version Mismatch

README says Symfony 7.1 in the architecture section, while `composer.json` uses Symfony 7.4 constraints.

### Recommendation

Update README to match actual dependencies.

#### PHPUnit Version

`phpunit/phpunit` is `^9.5`, which is older for PHP 8.3 projects.

### Recommendation

Consider upgrading to PHPUnit 10 or 11 if compatible with Symfony PHPUnit Bridge and project constraints.

---

## 9. Rate Limiting Review

The controller uses:
```
text
limiter.submit_ip
limiter.submit_token
```
Search results indicate these are configured in `config/packages/framework.yaml`.

### Positive Findings

- IP-level and token-level rate limiting are both present.
- Token limiter hashes raw token before using it as a limiter key.

### Recommendations

- Rate-limit link generation/key lookup.
- Rate-limit server-side verification endpoint if retained.
- Use shared backing storage for production multi-instance deployments.
- Ensure limiter storage does not reset too easily in production.
- Tune limits separately for:
  - link generation
  - message submission by IP
  - message submission by token
  - verification

---

## 10. Frontend Review Checklist

Frontend files were not fully inspected during this review.

The frontend should be checked for:

- Plaintext never leaves browser.
- Plaintext is not logged to console.
- Plaintext is cleared after submit.
- Submit button disables during encryption/submission.
- OpenPGP.js dependency is pinned.
- Public key fingerprint is displayed.
- Public key source is displayed.
- CSP prevents untrusted scripts.
- No third-party tracking scripts are loaded.

Recommended Content Security Policy baseline:
```
text
default-src 'self';
script-src 'self';
style-src 'self' 'unsafe-inline';
img-src 'self' data:;
connect-src 'self';
base-uri 'none';
form-action 'self';
frame-ancestors 'none';
```
This should be adjusted based on actual AssetMapper/Stimulus requirements.

---

## 11. Docker and Deployment Review

Docker files were present but not fully inspected.

Production deployment should ensure:

- PHP-FPM runs as non-root.
- Private key is mounted read-only.
- Private key permissions are `0600`.
- GPG home is not group/world-readable.
- `APP_ENV=prod`.
- `APP_DEBUG=0`.
- TLS is enforced.
- HSTS is enabled.
- Logs scrub token paths.
- Secrets are provided through a secret manager.
- `APP_SECRET` is generated with high entropy.
- PGP private key passphrase is required in production.
- Mailer transport uses TLS/authentication as needed.

---

## 12. Error Handling and Logging

### Positive Findings

- Message contents are not logged.
- Internal errors are hidden from API responses.
- Invalid/expired token handling is generic.

### Concern

Invalid or expired links are logged at error level:
```
php
$this->logger->error('Invalid or expired link', ...)
```
This is likely expected behavior and should not be treated as an application error.

### Recommendation

Use `notice` or `warning` instead.

---

## 13. Testing Recommendations

Existing tests are referenced in README, including:

- `BootstrapTest`
- `TokenLinkServiceTest`
- `PgpSigningServiceTest`

Additional tests should be added.

### Token Tests

- Invalid base64 token.
- Tampered HMAC.
- Tampered IV.
- Tampered ciphertext.
- Expired token.
- Malformed decrypted JSON.
- Missing email.
- Missing expiration.
- Invalid expiration type.
- Secret rotation invalidates old tokens.
- Email normalization.

### PGP Key Lookup Tests

- Invalid email causes no network call.
- First keyserver fails, second succeeds.
- All keyservers fail.
- HTTP 500 is ignored.
- Response contains marker but invalid key.
- Returned key UID does not match requested email.
- Multiple key blocks.
- Timeout behavior.

### PGP Signing Tests

- Missing private key.
- Unreadable private key.
- Private key permissions too broad.
- Wrong passphrase.
- Missing public key.
- Invalid public key during verification.
- Bad signature.
- Signature from different key.
- Expired/revoked key if practical.
- Verification does not pollute signing keyring.

### Controller Tests

- Invalid JSON.
- Missing CSRF.
- Invalid CSRF.
- Missing token.
- Malformed token.
- Expired token.
- DTO validation occurs before token limiter.
- IP rate limit exceeded.
- Token rate limit exceeded.
- Email generated with absolute verify URL.
- Plaintext is never present in outbound email when encrypted input is supplied.

---

## 14. Prioritized Findings

### High Priority

1. Tighten PGP signature verification.
2. Avoid or isolate process-global `GNUPGHOME` mutation.
3. Resolve browser-only verification documentation mismatch.
4. Reconsider public key trust model and legacy keyserver fallback.
5. Scrub or avoid token-in-path logging.

### Medium Priority

1. Generate absolute URLs in email.
2. Validate DTO before token-specific rate limiter consumption.
3. Rate-limit link generation.
4. Reduce default token expiration.
5. Add plain-text email body.
6. Re-enable Composer platform checks.
7. Improve frontend CSP.
8. Add fingerprint display/confirmation.

### Low Priority

1. Split `DefaultController`.
2. Fix README dependency version mismatch.
3. Lower log level for invalid links.
4. Remove unused controller parameters/imports.
5. Fix comments and formatting.
6. Upgrade PHPUnit if feasible.

---

## 15. Overall Assessment

Lockpost is a promising and thoughtfully designed application. It already avoids many common mistakes by not storing plaintext, not using a database for messages, signing forwarded ciphertext, and using authenticated tokens.

Before production use, the project should focus on security semantics and operational hardening:

- Define the trust model for public keys.
- Make signature verification strict.
- Avoid global GPG environment mutation.
- Be precise in privacy documentation.
- Prevent token leakage through logs.
- Expand negative-path tests.
