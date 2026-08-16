
# Lockpost Review Task List

Date: 2026-08-16  
Source Review: `jetbrains-tasklist.md`

---

## Legend

Priority:

- `P0` — Critical / production blocker
- `P1` — High priority
- `P2` — Medium priority
- `P3` — Low priority / cleanup

Status:

- `TODO`
- `IN_PROGRESS`
- `DONE`
- `WONT_DO`
- `BLOCKED`

---

## P0 — Production Blockers

### 1. Tighten PGP Signature Verification

Status: `TODO`  
Area: Security / Cryptography  
Files:

- `src/Service/PgpSigningService.php`
- `tests/Service/PgpSigningServiceTest.php`

Problem:

Current verification appears to accept signatures when the GPG summary does not contain `GNUPG_SIGSUM_RED`. This may accept signatures that are not strictly valid.

Tasks:

- [ ] Identify exact `gnupg::verify()` return structure for valid, invalid, expired, revoked, and unknown signatures.
- [ ] Require explicit good/valid signature status.
- [ ] Reject revoked signatures.
- [ ] Reject expired signatures if GPG exposes that status.
- [ ] Reject bad signatures.
- [ ] Ensure the signature fingerprint matches the imported public key fingerprint.
- [ ] Add regression tests for bad signatures.
- [ ] Add regression tests for signature from a different key.
- [ ] Add regression tests for invalid public key input.
- [ ] Add regression tests for malformed signed message input.

Acceptance Criteria:

- Invalid or mismatched signatures return `false` or controlled validation failure.
- A valid signature from the expected key returns `true`.
- Verification does not accept merely “not red” results.

---

### 2. Remove or Isolate Process-Global `GNUPGHOME` Mutation

Status: `TODO`  
Area: Security / Runtime Isolation  
Files:

- `src/Service/PgpSigningService.php`
- `config/services.yaml`
- tests as needed

Problem:

`putenv("GNUPGHOME=...")` mutates process-global state. This is brittle in long-running or concurrent contexts.

Tasks:

- [ ] Investigate whether PHP `gnupg` extension supports explicit home directory configuration.
- [ ] If supported, replace `putenv()` with explicit home directory config.
- [ ] If unsupported, evaluate using Symfony Process with `gpg --homedir`.
- [ ] Consider separate implementation paths for signing and verification.
- [ ] Add tests proving verification does not affect signing keyring.
- [ ] Add tests proving signing still works after failed verification.
- [ ] Document operational constraints if `putenv()` must remain.

Acceptance Criteria:

- Signing and verification do not rely on shared mutable environment where avoidable.
- Temporary verification keyrings cannot interfere with signing.
- Tests cover back-to-back signing and verification operations.

---

### 3. Resolve Browser-Only Verification Documentation Mismatch

Status: `TODO`  
Area: Privacy / Documentation / Product Behavior  
Files:

- `README.md`
- `templates/default/verify.html.twig`
- `src/Controller/DefaultController.php`
- privacy page template

Problem:

README claims verification runs entirely in browser, but a server-side verify POST endpoint exists.

Tasks:

- [ ] Confirm whether frontend performs client-side verification.
- [ ] If server-side endpoint is obsolete, remove `/verify/signature`.
- [ ] If server-side endpoint is required, update README.
- [ ] Update privacy page to state what data is submitted during verification.
- [ ] Add tests for verify route only if retained.
- [ ] Rate-limit verify route if retained.

Acceptance Criteria:

- Documentation accurately reflects actual behavior.
- Users are not told that verification is browser-only if data is submitted to the server.

---

### 4. Prevent Token Leakage Through Logs

Status: `TODO`  
Area: Privacy / Operations  
Files:

- `docker/nginx` config if present
- `docker-compose.prod.yml`
- deployment docs
- Symfony/framework logging config

Problem:

Tokens are present in `/submit/{token}` URLs and can leak through access logs, browser history, reverse proxies, and referrers.

Tasks:

- [ ] Review NGINX access log format.
- [ ] Scrub or disable logging of `/submit/{token}` path values.
- [ ] Add `Referrer-Policy: no-referrer`.
- [ ] Consider `Cache-Control: no-store` on submit pages.
- [ ] Document token URL sensitivity.
- [ ] Consider future migration to URL-fragment token flow.
- [ ] Ensure errors do not log raw tokens.

Acceptance Criteria:

- Production logs do not store full submission tokens.
- Security headers reduce accidental token leakage.

---

## P1 — High Priority

### 5. Rework Public Key Trust Model

Status: `TODO`  
Area: Security / PGP Identity  
Files:

- `src/Service/PgpKeyService.php`
- submit/link templates
- tests

Problem:

Traditional keyservers may return unverified/stale/spoofed key associations.

Tasks:

- [ ] Prefer WKD lookup if feasible.
- [ ] Keep `keys.openpgp.org` as primary source.
- [ ] Reconsider fallback to `keyserver.ubuntu.com`.
- [ ] Reconsider fallback to `pgp.mit.edu`.
- [ ] Parse returned PGP key.
- [ ] Verify UID/email matches requested email.
- [ ] Return key source metadata.
- [ ] Display key fingerprint to user.
- [ ] Display key source to user.
- [ ] Add tests for mismatched UID/email.
- [ ] Add tests for invalid PGP block with marker text.

Acceptance Criteria:

- App does not blindly trust any armored block returned by a keyserver.
- Sender can see fingerprint/source before using the link.

---

### 6. Make Email URLs Absolute

Status: `TODO`  
Area: Email / UX  
Files:

- `src/Controller/DefaultController.php`
- tests

Problem:

`generateUrl('app_verify')` likely returns a relative URL in the email template.

Tasks:

- [ ] Import `Symfony\Component\Routing\Generator\UrlGeneratorInterface`.
- [ ] Generate verify URL with `UrlGeneratorInterface::ABSOLUTE_URL`.
- [ ] Confirm trusted host/proxy config is correct in production.
- [ ] Add controller or mailer test asserting absolute URL.

Acceptance Criteria:

- Email contains a full absolute verification URL.

---

### 7. Validate DTO Before Token-Specific Rate Limiter

Status: `TODO`  
Area: Correctness / Abuse Prevention  
Files:

- `src/Controller/DefaultController.php`
- controller tests

Problem:

Token limiter is consumed before DTO validation errors are returned.

Tasks:

- [ ] Move DTO validation before token limiter consumption.
- [ ] Return validation errors before hashing/limiting malformed tokens.
- [ ] Validate/decrypt token before consuming token-specific limiter if appropriate.
- [ ] Add test for malformed token not polluting token limiter.
- [ ] Add test for valid token rate limiting.

Acceptance Criteria:

- Invalid DTOs do not consume token-specific limiter buckets.
- Valid requests are still rate-limited.

---

### 8. Add Rate Limiting to Link Generation

Status: `TODO`  
Area: Abuse Prevention  
Files:

- `config/packages/framework.yaml`
- `src/Controller/DefaultController.php`
- tests

Problem:

The homepage form triggers public key lookup, which performs outbound network requests.

Tasks:

- [ ] Add IP-based limiter for link generation.
- [ ] Consider stricter limiter for failed key lookups.
- [ ] Return friendly error when limited.
- [ ] Add tests for link generation rate limiting.
- [ ] Ensure limiter storage is production-suitable.

Acceptance Criteria:

- Attackers cannot freely trigger unlimited keyserver lookups.

---

### 9. Shorten Default Token Expiration

Status: `TODO`  
Area: Security / Product Policy  
Files:

- `src/Service/TokenLinkService.php`
- `config/services.yaml`
- `.env.example`
- `.env.production.example`
- README
- tests

Problem:

Default token lifetime is 30 days. Stateless tokens are reusable and non-revocable.

Tasks:

- [ ] Make token lifetime configurable via environment variable.
- [ ] Choose safer production default, e.g. 24 hours or 7 days.
- [ ] Update README.
- [ ] Add tests for custom expiration.
- [ ] Add UI copy showing expiry.

Acceptance Criteria:

- Token lifetime is configurable.
- Default lifetime is conservative.
- Users understand links expire.

---

### 10. Add Plain-Text Email Alternative

Status: `TODO`  
Area: Email / Accessibility / Compatibility  
Files:

- `src/Controller/DefaultController.php`
- `templates/email/message.txt.twig`
- tests

Problem:

Only HTML email body was observed.

Tasks:

- [ ] Create plain-text email template.
- [ ] Include encrypted message.
- [ ] Include signed message.
- [ ] Include server public key or download URL.
- [ ] Use `$email->text(...)`.
- [ ] Test email contains both HTML and text parts.

Acceptance Criteria:

- Outgoing email is multipart HTML + plain text.

---

## P2 — Medium Priority

### 11. Consider AEAD Token Format v2

Status: `TODO`  
Area: Cryptography  
Files:

- `src/Service/TokenLinkService.php`
- tests

Problem:

AES-CBC + HMAC is valid but more error-prone than AEAD.

Tasks:

- [ ] Design versioned token format.
- [ ] Implement `v2` using Sodium XChaCha20-Poly1305 or AES-256-GCM.
- [ ] Keep backward compatibility for `v1` tokens until expiry.
- [ ] Add tests for both versions.
- [ ] Document migration behavior.

Acceptance Criteria:

- New tokens use AEAD.
- Old tokens continue to validate during migration window.

---

### 12. Enforce `APP_SECRET` Strength

Status: `TODO`  
Area: Security / Configuration  
Files:

- `src/Kernel.php` or compiler pass/service
- config
- tests
- README

Problem:

Token security depends heavily on `APP_SECRET`.

Tasks:

- [ ] Add startup validation for minimum length.
- [ ] Recommend at least 32 random bytes encoded as hex/base64.
- [ ] Fail fast in prod if weak.
- [ ] Update `.env.example`.
- [ ] Update production docs.

Acceptance Criteria:

- Weak production `APP_SECRET` causes clear startup failure.

---

### 13. Re-enable Composer Platform Checks

Status: `TODO`  
Area: Dependency / Deployment  
Files:

- `composer.json`
- CI config if present

Problem:

`platform-check` is disabled.

Tasks:

- [ ] Remove `"platform-check": false` or set it to `true`.
- [ ] Confirm Docker image includes required PHP extensions.
- [ ] Run Composer install in CI.
- [ ] Document any reason if keeping disabled.

Acceptance Criteria:

- Missing required PHP extensions are detected early.

---

### 14. Upgrade PHPUnit If Feasible

Status: `TODO`  
Area: Testing / Maintenance  
Files:

- `composer.json`
- tests

Problem:

Project uses PHPUnit `^9.5` with PHP 8.3.

Tasks:

- [ ] Check compatibility with Symfony PHPUnit Bridge.
- [ ] Upgrade to PHPUnit 10 or 11 if practical.
- [ ] Update test annotations/attributes as needed.
- [ ] Run full test suite.

Acceptance Criteria:

- Tests run on supported PHPUnit version for PHP 8.3.

---

### 15. Add Security Headers

Status: `TODO`  
Area: Web Security  
Files:

- Symfony response listener or NGINX config
- tests if practical

Tasks:

- [ ] Add `Content-Security-Policy`.
- [ ] Add `Referrer-Policy: no-referrer`.
- [ ] Add `X-Content-Type-Options: nosniff`.
- [ ] Add `Frame-Options` or CSP `frame-ancestors 'none'`.
- [ ] Add `Strict-Transport-Security` in production.
- [ ] Add `Cache-Control: no-store` for sensitive submit pages.
- [ ] Validate AssetMapper/Stimulus compatibility with CSP.

Acceptance Criteria:

- Security headers are present in production responses.
- CSP does not break frontend encryption workflow.

---

### 16. Review and Harden Frontend Encryption Flow

Status: `TODO`  
Area: Frontend / Security  
Files:

- `assets`
- submit templates
- frontend tests if present

Tasks:

- [ ] Confirm plaintext is never sent to backend.
- [ ] Confirm plaintext is not logged to console.
- [ ] Clear plaintext after successful send.
- [ ] Disable submit button while encrypting/sending.
- [ ] Display public key fingerprint.
- [ ] Display key source.
- [ ] Handle encryption errors clearly.
- [ ] Confirm OpenPGP.js is pinned.
- [ ] Confirm no third-party tracking scripts exist.

Acceptance Criteria:

- Plaintext remains browser-only.
- Sender can inspect key identity before encrypting.

---

### 17. Add Optional Stateful Link Mode

Status: `TODO`  
Area: Architecture / Security  
Files:

- new storage/entity if database introduced
- `TokenLinkService`
- config
- docs

Problem:

Stateless links cannot be revoked or made one-time-use.

Tasks:

- [ ] Decide whether production mode should support token nonce storage.
- [ ] Store only nonce/token hash, not messages.
- [ ] Support revocation.
- [ ] Support one-time use or max submissions.
- [ ] Keep stateless mode available if desired.
- [ ] Document trade-offs.

Acceptance Criteria:

- Operators can choose stronger link controls without storing messages.

---

## P3 — Cleanup / Maintainability

### 18. Split `DefaultController`

Status: `TODO`  
Area: Code Organization  
Files:

- `src/Controller/DefaultController.php`
- new controller files
- route tests

Tasks:

- [ ] Create `LinkController`.
- [ ] Create `MessageController`.
- [ ] Create `VerificationController`.
- [ ] Create `StaticPageController`.
- [ ] Move methods without changing route names.
- [ ] Run route/debug checks.
- [ ] Run tests.

Acceptance Criteria:

- Routes continue to work.
- Controllers have focused responsibilities.

---

### 19. Fix README Symfony Version Mismatch

Status: `TODO`  
Area: Documentation  
Files:

- `README.md`

Problem:

README says Symfony 7.1 while `composer.json` requires Symfony 7.4.

Tasks:

- [ ] Update README tech stack section.
- [ ] Confirm PHP version documentation.
- [ ] Confirm local setup command paths.
- [ ] Confirm project directory name in clone instructions.

Acceptance Criteria:

- README matches actual dependencies and project structure.

---

### 20. Lower Log Level for Invalid Links

Status: `TODO`  
Area: Logging  
Files:

- `src/Controller/DefaultController.php`

Problem:

Invalid/expired links are logged as errors.

Tasks:

- [ ] Change log level from `error` to `notice` or `warning`.
- [ ] Ensure raw token is not logged.
- [ ] Keep reason message generic if needed.

Acceptance Criteria:

- Expected invalid-link behavior does not pollute error logs.

---

### 21. Remove Unused Parameters and Imports

Status: `TODO`  
Area: Code Cleanup  
Files:

- `src/Controller/DefaultController.php`

Tasks:

- [ ] Remove unused `Request $request` from `verifySignaturePage()` if unused.
- [ ] Remove unused imports.
- [ ] Run static analysis.

Acceptance Criteria:

- No unused imports or parameters reported by IDE/static analysis.

---

### 22. Fix Minor Comment/Formatting Issues

Status: `TODO`  
Area: Code Style  
Files:

- `src/Controller/DefaultController.php`
- other touched files

Tasks:

- [ ] Fix missing closing parenthesis in comment:
```
php
// Rate limiting by submission token (prevent flooding of a single valid token
```
- [ ] Normalize constructor formatting.
- [ ] Ensure comments match behavior.
- [ ] Run PHP CS fixer or project formatter.

Acceptance Criteria:

- Code style is consistent.
- Comments are accurate.

---

## Additional Test Work

### 23. TokenLinkService Security Test Suite

Status: `TODO`  
Priority: `P1`  
Files:

- `tests/Service/TokenLinkServiceTest.php`

Tasks:

- [ ] Test invalid base64.
- [ ] Test tampered HMAC.
- [ ] Test tampered IV.
- [ ] Test tampered ciphertext.
- [ ] Test expired token.
- [ ] Test malformed JSON payload.
- [ ] Test missing email.
- [ ] Test missing expiration.
- [ ] Test non-numeric expiration.
- [ ] Test secret rotation invalidates token.
- [ ] Test email normalization.

---

### 24. PgpKeyService Security Test Suite

Status: `TODO`  
Priority: `P1`  
Files:

- `tests/Service/PgpKeyServiceTest.php`

Tasks:

- [ ] Invalid email rejects without network request.
- [ ] First server 404, second server valid.
- [ ] All servers fail.
- [ ] HTTP 500 ignored.
- [ ] Timeout handled.
- [ ] Marker text without valid PGP key rejected.
- [ ] Key UID mismatch rejected once UID validation is implemented.
- [ ] Multiple key blocks handled deterministically.

---

### 25. Controller Security Test Suite

Status: `TODO`  
Priority: `P1`  
Files:

- controller tests

Tasks:

- [ ] Invalid JSON returns 400.
- [ ] Missing CSRF returns 400.
- [ ] Invalid CSRF returns 400.
- [ ] Missing token returns 400.
- [ ] Malformed token returns 400.
- [ ] Expired token returns 400.
- [ ] IP limiter returns 429.
- [ ] Token limiter returns 429.
- [ ] DTO validation precedes token limiter.
- [ ] Email verify URL is absolute.
- [ ] Encrypted message is included in email.
- [ ] Plaintext is not included if frontend sends only ciphertext.

---

## Documentation Tasks

### 26. Update Privacy Documentation

Status: `TODO`  
Priority: `P1`  
Files:

- privacy template
- README

Tasks:

- [ ] State that server processes recipient email.
- [ ] State that server sees sender IP.
- [ ] State that encrypted message transits server.
- [ ] State whether verification is browser-only or server-side.
- [ ] State that no plaintext should be sent to server.
- [ ] State token/link reuse behavior.
- [ ] State token expiry behavior.
- [ ] State operational log considerations.

---

### 27. Add Production Hardening Guide

Status: `TODO`  
Priority: `P2`  
Files:

- `docs/production-hardening.md` or README section

Tasks:

- [ ] Document required environment variables.
- [ ] Document secure `APP_SECRET` generation.
- [ ] Document PGP private key permissions.
- [ ] Document TLS/HSTS.
- [ ] Document access-log token scrubbing.
- [ ] Document backup/rotation for signing key.
- [ ] Document rate limiter storage.
- [ ] Document mailer TLS/authentication requirements.
- [ ] Document `APP_DEBUG=0`.

---

## Suggested Implementation Order

1. Tighten PGP verification.
2. Resolve `GNUPGHOME` isolation.
3. Fix verification documentation/privacy mismatch.
4. Prevent token leakage in logs.
5. Make email URLs absolute.
6. Reorder DTO validation and token limiter.
7. Add link-generation rate limiting.
8. Rework public key trust model.
9. Add missing security tests.
10. Add security headers.
11. Shorten/configure token lifetime.
12. Add plain-text email alternative.
13. Re-enable Composer platform checks.
14. Split controller.
15. Clean up docs/style.

---

## Completion Criteria for Production Readiness

The project should not be considered production-ready until at least these are complete:

- [ ] Strict PGP signature verification.
- [ ] Safe GPG home isolation or documented/locked workaround.
- [ ] Accurate privacy documentation.
- [ ] Token path logging mitigation.
- [ ] Absolute email URLs.
- [ ] Link generation rate limiting.
- [ ] Public key trust model clarified.
- [ ] Security headers configured.
- [ ] Core negative-path tests passing.
- [ ] Production deployment guide updated.