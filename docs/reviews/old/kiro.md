# Technical Review: sym-pgp-ony (Lockpost)

**Reviewer:** Kiro AI  
**Date:** 2026-08-15  
**Scope:** Full codebase — architecture, security, code quality, frontend, infrastructure, testing  

---

## 1. Architecture & Design

### Single-Controller Pattern
Well-executed for this scope. All 9 routes live in `DefaultController`. The controller stays lean — delegates real logic to services and uses `AppException`/`ErrorHandler` as the error boundary.

**Minor:** `$errorHandler` is not declared `readonly` unlike the other four injected services (lines 36–46 of `DefaultController`). Should use constructor promotion to match the others.

### Service Boundaries
- `TokenLinkService` — token lifecycle only  
- `PgpKeyService` — network key lookup only  
- `PgpSigningService` — GnuPG operations only  
- No `MailerService` — email is built inline in `DefaultController::submitMessage()`. Acceptable at this scale but notable.

### DI Wiring and Parameters
`../../../config/services.yaml` is clean, follows dotted-notation convention, and explicitly wires scalar-arg services. One gap: `APP_MAIL_FROM` is missing from `../../../.env.example` — a fresh clone will throw at runtime.

**Recommendation:** Add `APP_MAIL_FROM=noreply@example.com` to `../../../.env.example`.

### Token System Design
Solid stateless token: `base64url(HMAC[32B] || IV[16B] || AES-256-CBC(JSON))`.
- Correct encrypt-then-MAC construction.
- HMAC over `IV || ciphertext` — correct.
- `hash_equals()` for constant-time comparison — correct.
- `AppException` re-thrown directly in `validateLink` to preserve failure reason — correct.
- Route constraint `[A-Za-z0-9_\-]++` correctly restricts to URL-safe base64.

---

## 2. Security

### S1 — Key Derivation (Medium)
**File:** `../../../src/Service/TokenLinkService.php`, `deriveKey()`  
`hash('sha256', $appSecret, true)` has no salt or domain separation. A weak `APP_SECRET` undermines the whole token system. The code comment even acknowledges this.  
**Recommendation:** Use `hash_hkdf('sha256', $appSecret, 32, 'lockpost-token-v1')` for proper key derivation.

### S2 — Token Expiry (Low — OK)
Expiry is encoded in the payload and protected by HMAC. Tampering is caught. No issue.

### S3 — HMAC Validation (OK)
Constant-time comparison via `hash_equals()`. Encrypt-then-MAC order is correct.

### S4 — User-Supplied Public Key Import (Low)
**File:** `../../../src/Service/PgpSigningService.php`, `verifySignature()`  
Accepts user-supplied `$publicKey` and calls `$gpg->import($publicKey)`. The form regex provides some filtering but the real guard is GnuPG rejecting malformed key material. Acceptable.

### S5 — GnuPG Double Initialization (Medium)
**File:** `../../../src/Service/PgpSigningService.php`  
`initializeGnuPG()` is called in the constructor *and* at the start of every `signMessage()` call — the private key is imported into the keyring twice per signing operation. Additionally, `PgpSigningService` is eagerly injected into `DefaultController`, so GnuPG initializes on every page request, including `/about` and `/privacy`.  
**Recommendation:** Make the service lazy-loaded, or remove the constructor call to `initializeGnuPG()` and rely solely on the per-call initialization in `signMessage()`.

### S6 — No-Protection PGP Keys (Low — by design)
Keys generated with `%no-protection` silently accept any passphrase. `PGP_PRIVATE_KEY_PASSPHRASE` provides no actual protection unless the key was generated with a real passphrase.

### S7 — `putenv()` Race Condition (Medium)
**File:** `../../../src/Service/PgpSigningService.php`, `initializeGnuPG()` and `verifySignature()`  
`putenv("GNUPGHOME=...")` is process-global. In PHP-FPM with multiple concurrent workers sharing a process, two requests can race between `putenv()` and `new gnupg()`.  
**Recommendation:** Use `$gpg->sethomedir()` if available in the installed extension version. Otherwise document the risk and consider a lock.

### S8 — PGP Regex Too Strict (Low)
**File:** `../../../src/Form/PgpVerifySignatureFormType.php`, `PGP_BODY_PATTERN`  
The pattern `[a-zA-Z0-9\\/+=\\s]+` rejects colons and hyphens. Real GnuPG-signed messages include armor headers like `Hash: SHA256` — these would fail validation on the server-side verify path.  
**Recommendation:** Test the pattern against real GnuPG output; it is likely too strict.

### S9 — Session Cookie SameSite (Low)
`framework.yaml` does not explicitly configure `cookie_samesite`. Symfony 7's default is `lax` which is acceptable, but it should be explicit.

### S10 — No Key Fingerprint Displayed (Low — by design)
The sender sees no fingerprint before encryption. A compromised keyserver could return a different key. Known limitation of keyserver-based approaches.

---

## 3. Code Quality

### Q1 — `$errorHandler` Not `readonly` (Minor)
**File:** `../../../src/Controller/DefaultController.php`, lines ~36–46  
`$errorHandler` is assigned in the constructor body rather than using promoted `readonly` property syntax, unlike the other four dependencies.

### Q2 — Stale PHPDoc (Minor)
**File:** `../../../src/Service/PgpSigningService.php`, constructor docblock  
Documents `ErrorHandler $errorHandler` as a parameter but it was removed from the constructor at some point.

### Q3 — Broken Exception Chains (Low)
**File:** `../../../src/Service/PgpSigningService.php`, `signMessage()` and `verifySignature()`  
Exceptions are wrapped in new `AppException` without passing `$e` as `$previous`. The original stack trace is lost.  
**Recommendation:** Use `new AppException('...', 0, $e)` to preserve the chain.

### Q4 — Exception Message Leaked in Flash (Medium)
**File:** `../../../src/Controller/DefaultController.php`, `verifyIsValidSignature()`  
`$this->addFlash('danger', 'Error during verification: ' . $e->getMessage())` exposes raw exception messages (potentially including internal paths or GnuPG error details) to the user.  
**Recommendation:** Log the full exception, flash a generic message.

### Q5 — Exception Message Leaked in JSON Response (Medium)
**File:** `../../../src/Controller/DefaultController.php`, `submitMessage()`  
`'error' => $e->getMessage()` returns raw exception detail to the client.  
**Recommendation:** Log the full exception, return a generic error message.

### Q6 — `handleControllerException` Always Returns HTTP 400 (Low)
**File:** `../../../src/Exception/ErrorHandler.php`, `handleControllerException()`  
Returns 400 regardless of exception type. A GnuPG failure or network error is more accurately a 500.

### Q7 — Barebones Error HTML (Low)
**File:** `../../../src/Exception/ErrorHandler.php`, `handleControllerException()`  
Returns a raw unstyled HTML string with no navigation or Bootstrap styling — looks completely different from the rest of the app.  
**Recommendation:** Render a Twig error template or use Symfony's error page system.

---

## 4. Bugs

### B1 — `verifySignature()` Argument Order (Medium)
**File:** `../../../src/Service/PgpSigningService.php`, `verifySignature()`  
```php
$info = $gpg->verify($signature, $message);
```
The gnupg extension's `verify()` expects the *signed text* (or full cleartext-signed block) as the first argument. For detached signature verification: `verify($detached_sig, false, $plaintext)`. The current call passes `$signature` first and `$message` second — this is likely backwards and would cause systematic server-side verification failures.

### B2 — GNUPG_SIGSUM_VALID Strict Equality (Low)
**File:** `../../../src/Service/PgpSigningService.php`, `verifySignature()`  
`GNUPG_SIGSUM_VALID = 0` and the check uses `=== 0`. If GnuPG returns any informational bits set alongside a valid signature, this check fails and throws even for a technically valid signature.

### B3 — Verify Form Server-Side Path Broken (Medium)
**File:** `../../../templates/default/verify.html.twig`  
The plain HTML `<textarea>` elements for the Stimulus controller don't have `name` attributes matching the Symfony form structure (`verify_signature_form[public_key]` etc.). With JavaScript disabled, the server-side form submission receives no field data — the server-side path is effectively broken for no-JS users.

### B4 — Dual Verify Path Format Mismatch (Design)
**File:** `../../../assets/controllers/verify_controller.js` vs `../../../src/Form/PgpVerifySignatureFormType.php`  
The client-side path uses `openpgp.readCleartextMessage()` (expects `-----BEGIN PGP SIGNED MESSAGE-----`), while the server-side form uses `PGP_MESSAGE_PATTERN` which expects `-----BEGIN PGP MESSAGE-----`. The two paths expect different PGP block types.

---

## 5. Testing

### T1 — Zero Tests for `TokenLinkService` (High)
**Location:** `../../../tests` — no `TokenLinkServiceTest.php` exists  
The core cryptographic component — which the entire security model depends on — has no unit tests whatsoever. Missing coverage for:
- `generateLink` + `validateLink` round-trip
- Expired token rejection
- HMAC tampering detection
- Malformed/truncated base64 input
- Padding edge cases

### T2 — PGP Tests Require Pre-Generated Keys (Medium)
**File:** `../../../tests/Service/PgpSigningServiceTest.php`  
Copies real keys from `../../../config/pgp` which don't exist on a fresh clone. CI will fail without running `init-pgp.sh` first.  
**Recommendation:** Check in a test-only key pair or generate one programmatically in `setUp()`.

### T3 — Missing Happy-Path Controller Test
No test for `DefaultController::index()` when the email *has* a valid key and the token is generated (`link.html.twig` path).

### T4 — `verifySignature` Success Path Not Tested
**File:** `../../../tests/Service/PgpSigningServiceTest.php`  
Both PGP verify tests test failure paths. No test verifies a successful `verifySignature()` call.

### T5 — Typo in Test Method Name
**File:** `../../../tests/Service/PgpSigningServiceTest.php`  
`testInvalidSighing` → should be `testInvalidSigning`.

### T6 — No-JS Server-Side Submit Flow Not Tested
The full `submitMessage` flow (encrypt → sign → send email) has no integration test.

---

## 6. Frontend

### F1 — `openpgp` Presence Check in `connect()` Redundant (Minor)
**File:** `../../../assets/controllers/submit_controller.js`, `connect()`  
Since OpenPGP.js is loaded via importmap, if it fails to load the import throws before `connect()` runs. The check is harmless but redundant.

### F2 — Turbo Listed in Tech Stack but Not Present (Minor)
`@symfony/ux-turbo` is in the tech stack description but absent from `importmap.php`, `../../../assets/app.js`, and `../../../assets/bootstrap.js`. Not a bug but worth clarifying.

### F3 — `execCommand('copy')` Deprecated (Low)
**File:** `../../../assets/controllers/clipboard_controller.js`  
The `document.execCommand('copy')` fallback is deprecated. Still works in most browsers but will eventually be removed. Consider removing the fallback and showing a manual-copy prompt instead.

---

## 7. Infrastructure

### I1 — PHP-FPM Port Exposed to Host (Medium)
**File:** `../../../docker-compose.yml`  
```yaml
ports:
  - "9000:9000"
  - "9001:9001"
```
PHP-FPM should only communicate with nginx internally, not be exposed to the host.  
**Recommendation:** Replace `ports` with `expose: [9000]`.

### I2 — Port 9001 Unused (Minor)
**File:** `../../../docker-compose.yml`  
Port 9001 is mapped but nothing uses it — likely a leftover from Xdebug setup.

### I3 — `client_max_body_size 512M` (Medium)
**File:** `../../../docker/nginx/default.conf`  
Extremely permissive for an app that only receives small text payloads (PGP messages are a few KB).  
**Recommendation:** Reduce to `1M` or even `64K`.

### I4 — `network_mode: host` in Prod (Medium)
**File:** `../../../docker-compose.prod.yml`  
Host networking for both nginx and php removes Docker's network isolation entirely.  
**Recommendation:** Use bridge networking with explicit port mapping.

### I5 — Composer Installer Not Signature-Verified (Minor)
**File:** `../../../docker/php/Dockerfile`  
`curl -sS https://getcomposer.org/installer | php` — no installer signature check. Consider using the official Composer Docker image or verifying the hash.

### I6 — Unused Extensions in PHP Image (Minor)
**File:** `../../../docker/php/Dockerfile`  
`pdo_mysql`, `redis`, and `gd` are installed but unused by the application. Increases image size and attack surface unnecessarily.

### I7 — Xdebug in Production Image (Minor)
**File:** `../../../docker/php/Dockerfile`  
Xdebug is built into the image. Should be excluded from production builds.

### I8 — `APP_SECRET` Committed in `../../../.env.test` (Medium)
**File:** `../../../.env.test`  
Contains `APP_SECRET=1ba516337c394d7c63f7c5542a5ba01f`. Even as a test-only secret, committing secrets to the repository normalizes a bad practice.

### I9 — NGINX Status Endpoints Publicly Accessible (Low)
**File:** `../../../docker/nginx/default.conf`  
`/nginx_status` and PHP-FPM `/status` + `/ping` endpoints are accessible to anyone without IP restriction.  
**Recommendation:** Restrict to `127.0.0.1` or internal monitoring IPs.

### I10 — Cache Cleared on Every Restart (Low)
**File:** `../../../docker/php/entrypoint.sh`  
Symfony cache is cleared on every container start. Acceptable for dev but adds cold-start latency in production.

### I11 — Messenger Bundle Unused with DB DSN (Low)
**File:** `../../../config/packages/messenger.yaml`, `../../../.env.example`  
`MESSENGER_TRANSPORT_DSN=doctrine://default` references a DB that doesn't exist. Will error if Messenger is ever triggered.

---

## 8. Priority Summary

| # | ID | Severity | Category | Issue |
|---|----|----------|----------|-------|
| 1 | T1 | **High** | Testing | Zero unit tests for `TokenLinkService` — core crypto component |
| 2 | B1 | **Medium** | Bug | `verifySignature()` argument order likely wrong for gnupg `verify()` |
| 3 | S7 | **Medium** | Security | `putenv(GNUPGHOME)` is process-global; FPM workers can race |
| 4 | Q4 | **Medium** | Quality | `verifyIsValidSignature` leaks `$e->getMessage()` in flash message |
| 5 | Q5 | **Medium** | Quality | `submitMessage` leaks `$e->getMessage()` in JSON response |
| 6 | B3 | **Medium** | Bug | Verify form textareas missing `name` attrs; server-side no-JS path broken |
| 7 | S1 | **Medium** | Security | Key derivation uses bare SHA-256 with no salt/context separation |
| 8 | T2 | **Medium** | Testing | PGP signing tests require pre-generated key files; brittle on CI |
| 9 | I1 | **Medium** | Infra | PHP-FPM port 9000 exposed to host in dev compose |
| 10 | I3 | **Medium** | Infra | `client_max_body_size 512M` excessive for a text-only app |
| 11 | I4 | **Medium** | Infra | `network_mode: host` in prod compose removes network isolation |
| 12 | I8 | **Medium** | Infra | `APP_SECRET` committed in `../../../.env.test` |
| 13 | S5 | **Medium** | Security | GnuPG key imported twice per signing; eager init on non-PGP pages |
| 14 | Q3 | **Low** | Quality | Exception chains broken; `AppException` wrapping loses `$previous` |
| 15 | Q2 | **Low** | Quality | Stale PHPDoc on `PgpSigningService` constructor |
| 16 | B2 | **Low** | Bug | `GNUPG_SIGSUM_VALID === 0` strict check may fail on valid sigs with info bits |
| 17 | B4 | Low | Design | Dual verify paths expect different PGP block types |
| 18 | I9 | **Low** | Infra | NGINX status endpoints publicly accessible |
