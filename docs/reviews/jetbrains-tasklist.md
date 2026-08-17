
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

Status: `DONE`  
Area: Security / Cryptography  
Files:

- `src/Service/PgpSigningService.php`
- `tests/Service/PgpSigningServiceTest.php`

Problem:

Current verification appears to accept signatures when the GPG summary does not contain `GNUPG_SIGSUM_RED`. This may accept signatures that are not strictly valid.

Tasks:

- [x] Identify exact `gnupg::verify()` return structure for valid, invalid, expired, revoked, and unknown signatures.
- [x] Require explicit good/valid signature status.
- [x] Reject revoked signatures.
- [x] Reject expired signatures if GPG exposes that status.
- [x] Reject bad signatures.
- [x] Ensure the signature fingerprint matches the imported public key fingerprint.
- [x] Add regression tests for bad signatures.
- [x] Add regression tests for signature from a different key.
- [x] Add regression tests for invalid public key input.
- [x] Add regression tests for malformed signed message input.

Acceptance Criteria:

- Invalid or mismatched signatures return `false` or controlled validation failure.
- A valid signature from the expected key returns `true`.
- Verification does not accept merely “not red” results.

---

### 2. Remove or Isolate Process-Global `GNUPGHOME` Mutation

Status: `DONE`  
Area: Security / Runtime Isolation  
Files:

- `src/Service/PgpSigningService.php`
- `config/services.yaml`
- tests as needed

Problem:

`putenv("GNUPGHOME=...")` mutates process-global state. This is brittle in long-running or concurrent contexts.

Tasks:

- [x] Investigate whether PHP gnupg extension supports explicit home directory configuration.
- [x] If supported, replace putenv() with explicit home directory config.
- [x] If unsupported, evaluate using Symfony Process with gpg --homedir.
- [x] Consider separate implementation paths for signing and verification.
- [x] Add tests proving verification does not affect signing keyring.
- [x] Add tests proving signing still works after failed verification.
- [x] Document operational constraints if putenv() must remain.

Acceptance Criteria:

- Signing and verification do not rely on shared mutable environment where avoidable.
- Temporary verification keyrings cannot interfere with signing.
- Tests cover back-to-back signing and verification operations.

---

### 3. Resolve Browser-Only Verification Documentation Mismatch

Status: `WONT_DO`  
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

Status: `DONE`  
Area: Privacy / Operations  
Files:

- `docker/nginx` config if present
- `docker-compose.prod.yml`
- deployment docs
- Symfony/framework logging config

Problem:

Tokens are present in `/submit/{token}` URLs and can leak through access logs, browser history, reverse proxies, and referrers.

Tasks:

- [x] Review NGINX access log format.
- [x] Scrub or disable logging of /submit/{token} path values.
- [x] Add Referrer-Policy: no-referrer.
- [x] Consider Cache-Control: no-store on submit pages.
- [x] Document token URL sensitivity.
- [x] Consider future migration to URL-fragment token flow.
- [x] Ensure errors do not log raw tokens.

Acceptance Criteria:

- Production logs do not store full submission tokens.
- Security headers reduce accidental token leakage.

---

## P1 — High Priority

### 5. Rework Public Key Trust Model

Status: `DONE`  
Area: Security / PGP Identity  
Files:

- `src/Service/PgpKeyService.php`
- submit/link templates
- tests

Problem:

Traditional keyservers may return unverified/stale/spoofed key associations.

Tasks:

- [x] Prefer WKD lookup if feasible.
- [x] Keep `keys.openpgp.org` as primary source.
- [x] Reconsider fallback to `keyserver.ubuntu.com`.
- [x] Reconsider fallback to `pgp.mit.edu`.
- [x] Parse returned PGP key.
- [x] Verify UID/email matches requested email.
- [x] Return key source metadata.
- [x] Display key fingerprint to user.
- [x] Display key source to user.
- [x] Add tests for mismatched UID/email.
- [x] Add tests for invalid PGP block with marker text.

Acceptance Criteria:

- App does not blindly trust any armored block returned by a keyserver.
- Sender can see fingerprint/source before using the link.

---

### 6. Make Email URLs Absolute

Status: `DONE`  
Area: Email / UX  
Files:

- `src/Controller/DefaultController.php`
- tests

Problem:

`generateUrl('app_verify')` likely returns a relative URL in the email template.

Tasks:

- [x] Import `Symfony\Component\Routing\Generator\UrlGeneratorInterface`.
- [x] Generate verify URL with `UrlGeneratorInterface::ABSOLUTE_URL`.
- [x] Confirm trusted host/proxy config is correct in production.
- [ ] Add controller or mailer test asserting absolute URL.

Acceptance Criteria:

- Email contains a full absolute verification URL.

---

### 7. Validate DTO Before Token-Specific Rate Limiter

Status: `DONE`  
Area: Correctness / Abuse Prevention  
Files:

- `src/Controller/DefaultController.php`
- controller tests

Problem:

Token limiter is consumed before DTO validation errors are returned.

Tasks:

- [x] Move DTO validation before token limiter consumption.
- [x] Return validation errors before hashing/limiting malformed tokens.
- [x] Validate/decrypt token before consuming token-specific limiter if appropriate.
- [x] Add test for malformed token not polluting token limiter.
- [x] Add test for valid token rate limiting.

Acceptance Criteria:

- Invalid DTOs do not consume token-specific limiter buckets.
- Valid requests are still rate-limited.

---

### 8. Add Rate Limiting to Link Generation

Status: `DONE`  
Area: Abuse Prevention  
Files:

- `config/packages/framework.yaml`
- `src/Controller/DefaultController.php`
- tests

Problem:

The homepage form triggers public key lookup, which performs outbound network requests.

Tasks:

- [x] Add IP-based limiter for link generation.
- [x] Consider stricter limiter for failed key lookups.
- [x] Return friendly error when limited.
- [x] Add tests for link generation rate limiting.
- [x] Ensure limiter storage is production-suitable.

Acceptance Criteria:

- Attackers cannot freely trigger unlimited keyserver lookups.

---

### 9. Shorten Default Token Expiration

Status: `DONE`  
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

- [x] Make token lifetime configurable via environment variable.
- [x] Choose safer production default, e.g. 24 hours or 7 days.
- [x] Update README.
- [x] Add tests for custom expiration.
- [x] Add UI copy showing expiry.

Acceptance Criteria:

- Token lifetime is configurable.
- Default lifetime is conservative.
- Users understand links expire.

---

### 10. Add Plain-Text Email Alternative

Status: `DONE`  
Area: Email / Accessibility / Compatibility  
Files:

- `src/Controller/DefaultController.php`
- `templates/email/message.txt.twig`
- tests

Problem:

Only HTML email body was observed.

Tasks:

- [x] Create plain-text email template.
- [x] Include encrypted message.
- [x] Include signed message.
- [x] Include server public key or download URL.
- [x] Use `$email->text(...)`.
- [x] Test email contains both HTML and text parts.

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

Status: `DONE`  
Area: Dependency / Deployment  
Files:

- `composer.json`
- CI config if present

Problem:

`platform-check` is disabled.

Tasks:

- [x] Remove platform-check: false or set it to true.
- [x] Confirm Docker image includes required PHP extensions.
- [x] Run Composer install in CI.
- [x] Document any reason if keeping disabled.

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

Status: `DONE`  
Area: Web Security  
Files:

- Symfony response listener or NGINX config
- tests if practical

Tasks:

- [x] Add `Content-Security-Policy`.
- [x] Add `Referrer-Policy: no-referrer`.
- [x] Add `X-Content-Type-Options: nosniff`.
- [x] Add `Frame-Options` or CSP `frame-ancestors 'none'`.
- [x] Add `Strict-Transport-Security` in production.
- [x] Add `Cache-Control: no-store` for sensitive submit pages.
- [x] Validate AssetMapper/Stimulus compatibility with CSP.

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

Status: `DONE`  
Area: Documentation  
Files:

- `README.md`

Problem:

README says Symfony 7.1 while `composer.json` requires Symfony 7.4.

Tasks:

- [x] Update README tech stack section.
- [x] Confirm PHP version documentation.
- [x] Confirm local setup command paths.
- [x] Confirm project directory name in clone instructions.

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

Status: `DONE`  
Area: Code Cleanup  
Files:

- `src/Controller/DefaultController.php`

Tasks:

- [x] Remove unused `Request $request` from `verifySignaturePage()` if unused.
- [x] Remove unused imports.
- [x] Run static analysis.

Acceptance Criteria:

- No unused imports or parameters reported by IDE/static analysis.

---

### 22. Fix Minor Comment/Formatting Issues

Status: `DONE`  
Area: Code Style  
Files:

- `src/Controller/DefaultController.php`
- other touched files

Tasks:

- [x] Fix missing closing parenthesis in comment — was already resolved in earlier commits
- [x] Normalize constructor formatting — constructor was already properly formatted, fixed literal \n from earlier patch
- [x] Ensure comments match behavior — docblocks updated across Tasks 4-10
- [x] Run PHP CS fixer or project formatter — PHP CS Fixer not available; manual review done

Acceptance Criteria:

- Code style is consistent.
- Comments are accurate.

---

## Additional Test Work

### 23. TokenLinkService Security Test Suite

Status: `DONE`  
Priority: `P1`  
Files:

- `tests/Service/TokenLinkServiceTest.php`

Tasks:

- [x] Test invalid base64.
- [x] Test tampered HMAC.
- [x] Test tampered IV.
- [x] Test tampered ciphertext.
- [x] Test expired token.
- [x] Test malformed JSON payload.
- [x] Test missing email.
- [x] Test missing expiration.
- [x] Test non-numeric expiration.
- [x] Test secret rotation invalidates token.
- [x] Test email normalization.

---

### 24. PgpKeyService Security Test Suite

Status: `DONE`  
Priority: `P1`  
Files:

- `tests/Service/PgpKeyServiceTest.php`

Tasks:

- [x] Invalid email rejects without network request.
- [x] First server 404, second server valid.
- [x] All servers fail.
- [x] HTTP 500 ignored.
- [x] Timeout handled.
- [x] Marker text without valid PGP key rejected.
- [x] Key UID mismatch rejected once UID validation is implemented.
- [x] Multiple key blocks handled deterministically.

---

### 25. Controller Security Test Suite

Status: `DONE`  
Priority: `P1`  
Files:

- controller tests

Tasks:

- [x] Invalid JSON returns 400.
- [x] Missing CSRF returns 400.
- [x] Invalid CSRF returns 400.
- [x] Missing token returns 400.
- [x] Malformed token returns 400.
- [x] Expired token returns 400.
- [x] IP limiter returns 429.
- [x] Token limiter returns 429.
- [x] DTO validation precedes token limiter.
- [x] Email verify URL is absolute.
- [x] Encrypted message is included in email.
- [x] Plaintext is not included if frontend sends only ciphertext.

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