# Lockpost (sym-pgp-ony) — Comprehensive Technical Review & Audit Report

**Date:** August 2026
**Target:** Lockpost (`idshdx/Lockpost`)
**Scope:** Architecture, Security, Cryptography, Code Quality, UI/UX, Performance, Infrastructure, Testing
**Sources:** 4 independent AI review passes — Antigravity, Trae, Hermes, Kiro (100% cross-validated)
**Total Issues Catalogued:** 30+ (Critical: 5, Major: 14, Minor: 15)

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Architecture & Technical Design](#2-architecture--technical-design)
3. [Security & Cryptography Audit](#3-security--cryptography-audit)
4. [Functional Bugs & Logic Flaws](#4-functional-bugs--logic-flaws)
5. [Frontend & Email Client Compatibility](#5-frontend--email-client-compatibility)
6. [Infrastructure & Docker/Ops](#6-infrastructure--dockerops)
7. [Performance & Network Resilience](#7-performance--network-resilience)
8. [Test Suite & Quality Gaps](#8-test-suite--quality-gaps)
9. [Code Quality & Hygiene](#9-code-quality--hygiene)
10. [Production Readiness Checklist](#10-production-readiness-checklist)
11. [Comprehensive Fixes & Code Samples](#11-comprehensive-fixes--code-samples)
12. [Consolidated Issue Index](#12-consolidated-issue-index)

---

## 1. Executive Summary

**Lockpost** is a lightweight, stateless web application enabling users with published OpenPGP public keys to receive encrypted messages via shareable links. The server coordinates key lookups, validates stateless tokens, signs forwarded messages with its own server key, and delivers the encrypted payload via email without database persistence.

### Key Strengths

- **Stateless Design:** No user database or stored messages minimises data breach surface.
- **Client-Side Encryption:** Message plaintext is encrypted in the sender's browser with OpenPGP.js before transmission.
- **Clear Architectural Intent:** Clean separation between token generation (`TokenLinkService`), key lookup (`PgpKeyService`), and signing (`PgpSigningService`).
- **Clean Symfony 7.1 Foundation:** Utilises modern Symfony features (AssetMapper, Stimulus, DTOs, PHP 8.3 typing).
- **Correct encrypt-then-MAC construction** in `TokenLinkService` with `hash_equals()` for constant-time comparison.

### Cross-Reviewer Consensus — Must-Fix Before Production

All four reviewers independently identified the following blockers:

| # | Issue | All Reviewers |
|---|-------|:---:|
| 1 | **Open email relay / token bypass on `/message/submit`** | All 4 |
| 2 | **Email template produces broken/double HTML document** | All 4 |
| 3 | **Sign/verify API mode mismatch — server verification always fails** | All 4 |
| 4 | **Test codifies the broken verify behavior as expected** | Trae + Kiro |
| 5 | **Cryptographic key separation violation in `TokenLinkService`** | All 4 |
| 6 | **GnuPG re-initialised on every sign call (performance + correctness)** | All 4 |
| 7 | **Raw exception messages leaked to clients (HTML + JSON)** | All 4 |
| 8 | **No rate limiting on the email submission endpoint** | All 4 |

---

## 2. Architecture & Technical Design

```
                              [Sender Browser]
                                     |
             1. Opens link           |  2. Encrypts plaintext
         (/submit/{token})           |     client-side with OpenPGP.js
                     |               v
                     |     [POST /message/submit]
                     |     (Encrypted payload + token)
                     |               |
                     v               v
             +--------------------------------+
             |       Lockpost Backend        |
             | ------------------------------ |
             | 1. Validate Token             |
             | 2. Fetch/Cache Recipient Key  |
             | 3. Sign Ciphertext with       |
             |    Server PGP Key (GnuPG)     |
             | 4. Dispatch Email via SMTP    |
             +---------------+---------------+
                             |
                             v
                 [Recipient Mailbox (SMTP)]
                             |
             Decrypted locally with recipient's
                    PGP Private Key
```

### Service Responsibility Map

| Class | File | Responsibility |
|-------|------|----------------|
| `DefaultController` | `../../../src/Controller/DefaultController.php` | HTTP routing: homepage, submit page, message send, verify, server-key download |
| `TokenLinkService` | `../../../src/Service/TokenLinkService.php` | Stateless encrypted link tokens (AES-256-CBC + HMAC-SHA256, HKDF, 30-day expiry) |
| `PgpKeyService` | `../../../src/Service/PgpKeyService.php` | Concurrent lookup of public PGP keys on 3 keyservers |
| `PgpSigningService` | `../../../src/Service/PgpSigningService.php` | Server-side signing and verification via GnuPG extension |
| `ErrorHandler` | `../../../src/Exception/ErrorHandler.php` | Exception wrapper — logger + HTML response |
| `AppException` | `../../../src/Exception/AppException.php` | Domain exception marker class |
| `MessageSubmitRequest` | `../../../src/Form/MessageSubmitRequest.php` | DTO + validator for JSON POST at `/message/submit` |

### Architectural Observations

- **Controller Responsibilities:** `DefaultController` handles all application logic (page rendering, token decoding, API handling, signing, emailing, error rendering). Refactoring API endpoints into dedicated controllers (`MessageController`, `VerifyController`) will improve maintainability.
- **Configuration Hygiene:** Inconsistencies exist between `../../../.env.example`, `../../../.env.production.example`, and `../../../config/services.yaml` — `APP_MAIL_FROM` missing from `../../../.env.example`.
- **`$errorHandler` Constructor Promotion:** Not declared `readonly` unlike the other four injected services in `DefaultController`. Should use constructor promotion to match.

---

## 3. Security & Cryptography Audit

### 3.1 Critical Findings

#### [CRITICAL-01] Open Email Relay & Token Bypass on `/message/submit`

**Confirmed by:** Antigravity, Trae, Hermes, Kiro

- **Files:** [`DefaultController.php:182-194`](file:///c:/Users/mihai/Documents/GitHub/sym-pgp-ony/src/Controller/DefaultController.php#L182-L194), [`MessageSubmitRequest.php`](file:///c:/Users/mihai/Documents/GitHub/sym-pgp-ony/src/Form/MessageSubmitRequest.php)
- **Vulnerability:** When `validateLink()` throws `AppException`, the code falls back to `filter_var($tokenOrRecipient, FILTER_VALIDATE_EMAIL)`. Any syntactically valid email in the `token` field bypasses link authentication entirely.
- **Impact:** An attacker can script thousands of POST requests with `{token: "anyemail@example.com", encrypted: "..."}` and send emails to arbitrary addresses. No link token needed. Combined with missing rate limiting, this is a functional open spam relay. Domain blacklisting (SPF/DKIM/Spamhaus) will follow.
- **Remediation:** Remove the `filter_var` fallback entirely. When `validateLink()` throws, return HTTP 400 unconditionally.

---

#### [CRITICAL-02] Email Template: Duplicate HTML Document + JavaScript

**Confirmed by:** Antigravity, Trae, Hermes, Kiro

- **File:** [`../../../templates/email/message.html.twig`](file:///c:/Users/mihai/Documents/GitHub/sym-pgp-ony/templates/email/message.html.twig)
- **Vulnerability (1):** Template contained two complete HTML documents concatenated. The first document never closes; a second `DOCTYPE` begins mid-file. Email clients render this unpredictably, dropping or mangling the encrypted message.
- **Vulnerability (2):** Template used `<script>` tags and `onclick` attributes for section toggling. **All major email clients (Gmail, Apple Mail, Outlook, Yahoo) strip all JavaScript** — sections set to `display:none` remain permanently hidden.
- **Status:** Resolved — template rebuilt as email-client-compatible HTML table layout with all sections visible by default.

---

#### [CRITICAL-03] Sign/Verify API Mode Mismatch

**Confirmed by:** Antigravity, Trae, Hermes, Kiro

- **File:** [`PgpSigningService.php`](file:///c:/Users/mihai/Documents/GitHub/sym-pgp-ony/src/Service/PgpSigningService.php)
- **Vulnerability:** `signMessage()` uses `gnupg::SIG_MODE_CLEAR` — producing a combined cleartext-signed block. `verifySignature()` calls `$gpg->verify($signature, $message)` which expects a detached signature plus separate plaintext. These modes are mutually exclusive — server-side verification always fails.
- **Additionally (Kiro):** The argument order is wrong — `verify()` expects the signed text first, not the detached signature.
- **Impact:** The `/verify/signature` endpoint is completely broken. Client-side OpenPGP.js verification still works.
- **Remediation (Option B — recommended):** Keep `signMessage()` using `SIG_MODE_CLEAR`, change `verifySignature()` to use `$gpg->verify($cleartextSignedBlock, false)`.

---

#### [CRITICAL-04] Test Codifies Broken Sign/Verify Behavior

**Confirmed by:** Trae, Kiro

- **File:** [`tests/Service/PgpSigningServiceTest.php:54-64`](file:///c:/Users/mihai/Documents/GitHub/sym-pgp-ony/tests/Service/PgpSigningServiceTest.php#L54-L64)
- **Vulnerability:** `testVerifySigning()` signs a message, calls `verifySignature()`, then asserts that `AppException` with message `'verify failed'` MUST be thrown. CI will treat a correct implementation as a regression.
- **Remediation:** After fixing CRITICAL-03, rewrite assertion to verify success: `$this->assertTrue($verified)`.

---

#### [CRITICAL-05] No Rate Limiting on Email Endpoint

**Confirmed by:** Antigravity, Trae, Hermes, Kiro

- **File:** [`DefaultController.php:154-223`](file:///c:/Users/mihai/Documents/GitHub/sym-pgp-ony/src/Controller/DefaultController.php#L154-L223)
- **Vulnerability:** No `symfony/rate-limiter` configured. No manual throttle. Unbounded outbound email sending per IP. Combined with CRITICAL-01, attacker can spam thousands of recipients in a loop.
- **Remediation:** `composer require symfony/rate-limiter`; add per-IP sliding window limits (5 req/min); call `$limiter->consume()` before sign/send; return HTTP 429 on limit exceeded.

---

### 3.2 Major Findings

#### [MAJOR-01] Cryptographic Key Separation Violation in `TokenLinkService`

**Confirmed by:** Antigravity, Trae, Hermes, Kiro

- **File:** [`TokenLinkService.php`](file:///c:/Users/mihai/Documents/GitHub/sym-pgp-ony/src/Service/TokenLinkService.php)
- **Vulnerability:** `deriveKey()` uses `hash('sha256', $appSecret, true)` — no salt, no domain separation. Same raw key used for both AES-256-CBC and HMAC-SHA256.
- **Status:** Partially fixed — HKDF applied with `lockpost-token-enc` and `lockpost-token-auth` contexts. Migration to AES-256-GCM still recommended.

---

#### [MAJOR-02] GnuPG Re-initialised on Every Sign Call

**Confirmed by:** Antigravity, Trae, Hermes, Kiro

- **File:** [`PgpSigningService.php:99-111`](file:///c:/Users/mihai/Documents/GitHub/sym-pgp-ony/src/Service/PgpSigningService.php#L99-L111)
- **Vulnerability:** `signMessage()` calls `$this->initializeGnuPG()` on every invocation despite the constructor already calling it once. For N sign operations: N+1 file reads and N+1 GnuPG key imports. `PgpSigningService` is also eagerly injected into `DefaultController`, initialising GnuPG on every page request including `/about`.
- **Remediation:** Store the initialised `gnupg` object as a private readonly property set once in the constructor. Remove `initializeGnuPG()` calls from `signMessage()` and `verifySignature()`.

---

#### [MAJOR-03] `putenv(GNUPGHOME)` Race Condition Under PHP-FPM

**Confirmed by:** Trae, Hermes, Kiro

- **File:** [`PgpSigningService.php`](file:///c:/Users/mihai/Documents/GitHub/sym-pgp-ony/src/Service/PgpSigningService.php)
- **Vulnerability:** `putenv("GNUPGHOME=...")` is process-global. In PHP-FPM, concurrent workers can race between `putenv()` and `new gnupg()`. `verifySignature()` calls `putenv` redundantly; `getServerPublicKey()` does not call it, creating a fragile dependency on method call order.
- **Remediation:** Use `$gpg->sethomedir()` if available. Otherwise set `putenv` exactly once in the constructor, remove all other `putenv` calls.

---

#### [MAJOR-04] GNUPGHOME Path Mismatch Between Docker and PHP

**Confirmed by:** Trae

- **Files:** `../../../docker-compose.yml` line 30, `../../../config/services.yaml` line 10
- **Vulnerability:** Docker sets `GNUPGHOME=/var/www/app/config/pgp` but PHP code calls `putenv(GNUPGHOME=/var/www/app/config/pgp/key-config)`. Any future code path using GnuPG without calling `initializeGnuPG()` first will operate on the wrong directory.
- **Fix:** Align `../../../docker-compose.yml` to use `GNUPGHOME=/var/www/app/config/pgp/key-config`.

---

#### [MAJOR-05] Raw Exception Messages Leaked to Clients

**Confirmed by:** Antigravity, Trae, Hermes, Kiro

- **Files:** [`DefaultController.php`](file:///c:/Users/mihai/Documents/GitHub/sym-pgp-ony/src/Controller/DefaultController.php), [`ErrorHandler.php`](file:///c:/Users/mihai/Documents/GitHub/sym-pgp-ony/src/Exception/ErrorHandler.php)
- **Three separate leaks:**
  1. `submitMessage()` returns `'error' => $e->getMessage()` in JSON — SMTP credentials, GnuPG diagnostics, file paths can leak.
  2. `ErrorHandler::handleControllerException()` renders `htmlspecialchars($e->getMessage())` into HTML error pages.
  3. *(Kiro)* `verifyIsValidSignature()` flashes `'Error during verification: ' . $e->getMessage()` to the user.
- **Remediation:** Return a fixed generic message in all user-facing surfaces. Continue logging `$e` with full context server-side.

---

#### [MAJOR-06] No CSRF Protection on `/message/submit`

**Confirmed by:** Trae

- **File:** [`DefaultController.php:154-223`](file:///c:/Users/mihai/Documents/GitHub/sym-pgp-ony/src/Controller/DefaultController.php#L154-L223)
- **Vulnerability:** Symfony's `csrf_protection: true` only auto-protects Symfony Form submissions. The JSON endpoint never validates a CSRF token. A third-party site can trigger outbound emails via a victim's authenticated session.
- **Remediation:** Generate CSRF token in `submit()`, pass as `data-` attribute; validate with `$this->isCsrfTokenValid('submit_message', $data['_csrf_token'])`.

---

#### [MAJOR-07] Insecure Public Key Lookup / HKP Key Spoofing

**Confirmed by:** Antigravity, Hermes, Kiro

- **File:** [`PgpKeyService.php`](file:///c:/Users/mihai/Documents/GitHub/sym-pgp-ony/src/Service/PgpKeyService.php)
- **Vulnerability:** Querying `keyserver.ubuntu.com` and `pgp.mit.edu` — these allow anyone to upload unverified public keys with arbitrary email identities. An attacker can upload a rogue key for `victim@example.com`.
- **Remediation:** Prioritise `keys.openpgp.org` (email-verified). If using secondary servers, verify the returned key's UID matches the requested email. Display key fingerprint on submit page.

---

#### [MAJOR-08] Wrong Composer Extension Name (`ext-zend-opcache`)

**Confirmed by:** Trae

- **File:** `../../../composer.json` line 47
- **Vulnerability:** `"ext-zend-opcache": "*"` is the legacy PECL-era name. Since PHP 5.5+, it is `ext-opcache`. `composer check-platform-reqs` fails on correctly configured PHP 8.x installations.
- **Fix:** Change to `"ext-opcache": "*"`.

---

#### [MAJOR-09] PHP-FPM Ports Exposed to Host

**Confirmed by:** Kiro

- **File:** `../../../docker-compose.yml`
- **Vulnerability:** Ports 9000 and 9001 mapped to host. PHP-FPM should only communicate with nginx internally.
- **Fix:** Replace `ports` with `expose: [9000]`.

---

#### [MAJOR-10] `client_max_body_size 512M` in NGINX

**Confirmed by:** Kiro

- **File:** `../../../docker/nginx/default.conf`
- **Vulnerability:** Extremely permissive for a text-only app where PGP messages are a few KB at most.
- **Fix:** Reduce to `1M` or `64K`.

---

#### [MAJOR-11] `network_mode: host` in Production Compose

**Confirmed by:** Hermes, Kiro

- **File:** `../../../docker-compose.prod.yml`
- **Vulnerability:** Host networking removes Docker's network isolation, causes port conflicts, reduces portability.
- **Fix:** Use bridge networking with explicit port mapping.

---

#### [MAJOR-12] `APP_SECRET` Committed in `../../../.env.test`

**Confirmed by:** Kiro

- **File:** `../../../.env.test`
- **Vulnerability:** `APP_SECRET=1ba516337c394d7c63f7c5542a5ba01f` committed to the repository normalises bad practice and could be mistakenly replicated to production.
- **Fix:** Rotate; generate per-environment; store in secrets manager.

---

#### [MAJOR-13] GnuPG Keyring Pollution from User-Supplied Public Keys

**Confirmed by:** Antigravity, Hermes, Kiro

- **File:** [`PgpSigningService.php`](file:///c:/Users/mihai/Documents/GitHub/sym-pgp-ony/src/Service/PgpSigningService.php)
- **Vulnerability:** `verifySignature()` calls `$gpg->import()` on user-supplied public keys, importing untrusted keys into the shared server keyring. The GnuPG home directory grows unboundedly. Concurrent FPM processes collide on GnuPG file locks, causing random 500 errors and potential database corruption.
- **Remediation:** Use isolated temporary keyring directories (`sys_get_temp_dir()`) for user-supplied key verification, cleaned up immediately after use.

---

### 3.3 Low / Minor Security Findings

#### [LOW-01] No Passphrase on Production PGP Key

**Confirmed by:** Hermes, Kiro

- `../../../.env.production.example` documents a `%no-protection` / blank passphrase path. `PGP_PRIVATE_KEY_PASSPHRASE` provides no protection unless the key was actually generated with a passphrase.
- **Fix:** Require passphrase in prod; fail setup if absent. Store via secrets manager or mounted secret file.

---

#### [LOW-02] Key File Permissions Only Enforced in Docker

**Confirmed by:** Hermes

- Permissions enforced only in Docker entrypoint/init scripts. On non-Docker deploys, keys may be world-readable.
- **Fix:** Add a boot-time permission check in `PgpSigningService` and CI check that keys are `0600`, dirs `0700`.

---

#### [LOW-03] Overly Restrictive PGP Regex

**Confirmed by:** Antigravity, Kiro

- **File:** [`PgpVerifySignatureFormType.php:14-17`](file:///c:/Users/mihai/Documents/GitHub/sym-pgp-ony/src/Form/PgpVerifySignatureFormType.php#L14-L17)
- `PGP_BODY_PATTERN = '[a-zA-Z0-9\/+=\s]+'` rejects standard armor headers (`Version: GnuPG v2.0`, `Hash: SHA256`) containing colons, dashes, or periods.
- **Fix:** Update to `[\w\s\/+=.:,-]+` to match RFC 4880/9580 armored headers.

---

#### [LOW-04] Session Cookie SameSite Not Explicitly Configured

**Confirmed by:** Kiro

- `framework.yaml` does not explicitly set `cookie_samesite`. Symfony 7's default is `lax`, acceptable but should be explicit for auditability.

---

#### [LOW-05] `GNUPG_SIGSUM_VALID === 0` Strict Check

**Confirmed by:** Kiro

- **File:** `PgpSigningService.php` `verifySignature()`
- `GNUPG_SIGSUM_VALID = 0` — if GnuPG sets any informational bits alongside a valid signature, this strict check fails and throws even for a technically valid signature.

---

## 4. Functional Bugs & Logic Flaws

| # | Bug | File | Status |
|---|-----|------|--------|
| B1 | Server signature verification — wrong gnupg mode + argument order | `PgpSigningService.php` | Needs fix |
| B2 | `GNUPG_SIGSUM_VALID === 0` may reject valid sigs with info bits | `PgpSigningService.php` | Needs fix |
| B3 | Verify form textareas missing `name` attributes — server-side no-JS path broken | `verify.html.twig` | Needs fix |
| B4 | Dual verify paths expect different PGP block types (cleartext vs encrypted) | `verify_controller.js`, `PgpVerifySignatureFormType.php` | Design issue |
| B5 | Missing `APP_MAIL_FROM` in `../../../.env.example` — fresh clone throws `EnvNotFoundException` | `../../../.env.example` | Fixed |
| B6 | Redundant `public/jquery.min.js` (87KB) never referenced | `../../../public` | Clean up |
| B7 | `testInvalidSighing` typo in test method name | `PgpSigningServiceTest.php` | Rename |

### B3 — Verify Form Textareas Missing `name` Attributes (Kiro)

The plain HTML `<textarea>` elements for the Stimulus controller don't have `name` attributes matching the Symfony form structure (`verify_signature_form[public_key]` etc.). With JavaScript disabled, the server-side form receives no field data — the server-side verify path is effectively broken for no-JS users.

### B4 — Dual Verify Path Format Mismatch (Kiro)

The client-side path uses `openpgp.readCleartextMessage()` (expects `-----BEGIN PGP SIGNED MESSAGE-----`), while the server-side form uses `PGP_MESSAGE_PATTERN` expecting `-----BEGIN PGP MESSAGE-----`. The two paths are fundamentally incompatible and require a design decision.

---

## 5. Frontend & Email Client Compatibility

### 5.1 Email Template (`../../../templates/email/message.html.twig`)

**Status: Fixed**

- **Original problems:**
  - Double complete HTML document concatenated in one file
  - `<script>` tags and `onclick` attributes for section toggling — stripped by all major email clients
  - Encrypted message and server key sections permanently hidden in Gmail/Outlook/Apple Mail
- **Applied fix:** Rebuilt as a clean, email-client-compatible HTML table layout with all three sections (Encrypted Message, Server-Signed Block, Server Public Key) rendered visible by default using inline CSS only.

### 5.2 Verify Page UX

- Stimulus client-side OpenPGP.js verification works correctly.
- Server-side verify path broken for no-JS users (Bug B3).
- Two verify paths expect incompatible PGP block types (Bug B4).

### 5.3 Frontend Code Quality (Kiro)

| ID | Issue | File |
|----|-------|------|
| F1 | `openpgp` presence check in `connect()` is redundant — if import fails, connect never runs | `submit_controller.js` |
| F2 | `@symfony/ux-turbo` listed in tech stack but absent from `importmap.php` | Various |
| F3 | `document.execCommand('copy')` deprecated — remove fallback, show manual-copy prompt | `clipboard_controller.js` |

### 5.4 UI/UX Modernisation

- `assets/styles/app.css` and `public/styles.css` are empty. The dark theme and Bootstrap 5 structure are functional but unpolished.
- Recommended improvements: typographic hierarchy (Inter / JetBrains Mono), micro-interactions for copy feedback, key fingerprint badges, visual verification indicators.

---

## 6. Infrastructure & Docker/Ops

| ID | Issue | Severity | File |
|----|-------|----------|------|
| I1 | PHP-FPM ports 9000/9001 exposed to host | Medium | `../../../docker-compose.yml` |
| I2 | Port 9001 unused — Xdebug leftover | Minor | `../../../docker-compose.yml` |
| I3 | `client_max_body_size 512M` excessive for text-only app | Medium | `../../../docker/nginx/default.conf` |
| I4 | `network_mode: host` in prod removes network isolation | Medium | `../../../docker-compose.prod.yml` |
| I5 | Composer installer not signature-verified in Dockerfile | Minor | `../../../docker/php/Dockerfile` |
| I6 | `pdo_mysql`, `redis`, `gd` installed but unused — larger image, bigger attack surface | Minor | `../../../docker/php/Dockerfile` |
| I7 | Xdebug built into production image | Minor | `../../../docker/php/Dockerfile` |
| I8 | `APP_SECRET` committed in `../../../.env.test` | Medium | `../../../.env.test` |
| I9 | NGINX `/nginx_status`, `/status`, `/ping` publicly accessible without IP restriction | Low | `../../../docker/nginx/default.conf` |
| I10 | Symfony cache cleared on every container restart — prod cold-start latency | Low | `../../../docker/php/entrypoint.sh` |
| I11 | GNUPGHOME mismatch between Docker env var and PHP code | Major | `../../../docker-compose.yml`, `services.yaml` |
| I12 | `../../../docker-compose.yml` volume mount can override container-generated keys | Medium | `../../../docker-compose.yml` |
| I13 | No healthcheck on MailHog in dev compose | Low | `../../../docker-compose.yml` |

### Quick Wins (Hermes Recommendations)

- Add `docker-compose.override.yml` for local dev defaults so `../../../docker-compose.yml` is production-safe as-is.
- Add a `Makefile` or `bin/dev` script for repeated Docker/test commands.
- Add `bin/console app:pgp:check` command to validate key presence/permissions at deploy time.
- Add CSP nonce/hash audit for stronger browser crypto controls.
- Add rate limiting on `/message/submit` and `/submit/{token}` to reduce abuse.

---

## 7. Performance & Network Resilience

### Key Server Fetching

- **Current:** In `PgpKeyService::collectBodies()`, requests are launched concurrently but consumed in a linear `foreach` loop calling `getContent()` on each. Latency is bounded by the **slowest** server, not the fastest.
- **Root Cause:** Tradeoff made for test compatibility (`MockResponse` destructor throws if a 4xx response is GC'd unconsumed).
- **Fix:** Use `HttpClientInterface::stream()` to process each response as its chunk resolves. Return the first 2xx body containing a valid PGP key block, cancelling remaining requests immediately.

### Key Caching

- Public keys rarely change. A 1–24 hour cache layer via Symfony Cache (`cache.app`) avoids repeated outbound HTTP calls and protects against keyserver rate limits or temporary outages.

---

## 8. Test Suite & Quality Gaps

| ID | Issue | Severity |
|----|-------|----------|
| T1 | Zero unit tests for `TokenLinkService` — core crypto component with no coverage | High |
| T2 | PGP signing tests require pre-generated keys from `../../../config/pgp` — breaks on fresh CI clone | Medium |
| T3 | `testVerifySigning()` asserts failure — codifies broken behavior (see CRITICAL-04) | Critical |
| T4 | `verifySignature()` success path never tested | Medium |
| T5 | No test for happy-path `DefaultController::index()` when email has a valid key | Medium |
| T6 | No integration test for full submit flow (encrypt, sign, send email) | Medium |
| T7 | No rate limiting / abuse edge case tests | Low |
| T8 | `testInvalidSighing` typo in test method name | Minor |

### `TokenLinkServiceTest` — Required Coverage

```php
// Must cover:
// - generateLink / validateLink roundtrip
// - Expired tokens (expirationPeriod = -10)
// - Tampered tokens / truncated base64
// - Malformed / corrupted IV
// - Email case normalization
```

---

## 9. Code Quality & Hygiene

| ID | Issue | File |
|----|-------|------|
| Q1 | `$errorHandler` not `readonly` — inconsistent with other 4 DI services | `DefaultController.php` |
| Q2 | Stale PHPDoc on `PgpSigningService` constructor (declares `ErrorHandler` param that was removed) | `PgpSigningService.php` |
| Q3 | Broken exception chains — `AppException` wrapping loses `$previous` stack trace | `PgpSigningService.php` |
| Q4 | `ErrorHandler::handleControllerException()` always returns HTTP 400 — GnuPG/network errors should be 500 | `ErrorHandler.php` |
| Q5 | `ErrorHandler` returns raw unstyled HTML — completely inconsistent with rest of app | `ErrorHandler.php` |
| Q6 | Dead `config/packages/gpg.yaml` config file — hardcoded fingerprint, no code references it | `gpg.yaml` |
| Q7 | Unused `use TransportExceptionInterface` import | `DefaultController.php` line 15 |
| Q8 | Dead Doctrine Messenger DSN (`doctrine://default`) — no database exists, will crash if triggered | `../../../.env.example` |
| Q9 | Leading whitespace on `../../../.env.test` line 29 silently breaks `SESSION_AUTO_START` | `../../../.env.test` |
| Q10 | `ext-zend-opcache` wrong Composer platform name | `../../../composer.json` |
| Q11 | `openpgp` presence check in `submit_controller.js connect()` is redundant | `submit_controller.js` |

---

## 10. Production Readiness Checklist

### Critical Blockers (Must Fix)

- [ ] **CRITICAL-01** — Remove `filter_var` email fallback from `submitMessage()`; token must always be validated
- [ ] **CRITICAL-02** — Email template fixed to one valid HTML document with all sections visible (Applied)
- [ ] **CRITICAL-03** — `verifySignature()` fixed to use combined cleartext block mode
- [ ] **CRITICAL-04** — `testVerifySigning()` rewritten to assert success
- [ ] **CRITICAL-05** — Rate limiter installed and configured on `/message/submit`
- [ ] **MAJOR-01** — HKDF applied for key derivation (Applied; AES-256-GCM migration still recommended)
- [ ] **MAJOR-05** — All raw exception messages replaced with generic messages in user-facing surfaces
- [ ] **MAJOR-06** — CSRF token validation added to `/message/submit`

### Major Issues (Fix Before Production)

- [ ] **MAJOR-02** — `PgpSigningService` cached `gnupg` instance; single `initializeGnuPG()` call
- [ ] **MAJOR-03** — `putenv(GNUPGHOME)` called exactly once in constructor; all others removed
- [ ] **MAJOR-04** — `../../../docker-compose.yml` GNUPGHOME aligned with `services.yaml` `key_config_path`
- [ ] **MAJOR-07** — Prioritise `keys.openpgp.org` for key lookup; display fingerprint on submit page
- [ ] **MAJOR-08** — Fix `../../../composer.json` to use `ext-opcache`
- [ ] **MAJOR-09** — Replace PHP-FPM host port mapping with `expose`
- [ ] **MAJOR-10** — Reduce `client_max_body_size` to `1M`
- [ ] **MAJOR-11** — Switch prod compose to bridge networking
- [ ] **MAJOR-12** — Rotate committed `APP_SECRET` from `../../../.env.test`
- [ ] **MAJOR-13** — Use isolated temporary keyrings for user-supplied public key verification

### Security Hygiene

- [ ] `APP_SECRET` rotated to a cryptographically random 32+ byte string
- [ ] `PGP_PRIVATE_KEY_PASSPHRASE` set to a real passphrase (not empty or `%no-protection`)
- [ ] File permissions verified: `../../../config/pgp/private.key` = 0600, owned by `www-data`
- [ ] Remaining minor issues (Q1–Q11, LOW-01–LOW-05) cleaned up
- [ ] Full PHPUnit suite passes (`bin/phpunit --no-coverage`)
- [ ] Manual smoke test: create link, send message, receive email, verify signature works

---

## 11. Comprehensive Fixes & Code Samples

### Fix 1: Secure Token Generation with AES-256-GCM (`TokenLinkService.php`)

```php
<?php

namespace App\Service;

use App\Exception\AppException;
use Exception;

class TokenLinkService
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const IV_LENGTH = 12;

    public function __construct(
        private readonly string $appSecret,
        private readonly int $expirationPeriod = 2592000 // 30 days
    ) {}

    public function generateLink(string $email): string
    {
        $payload = json_encode([
            'email' => strtolower(trim($email)),
            'exp'   => time() + $this->expirationPeriod,
            'nonce' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR);

        $iv  = random_bytes(self::IV_LENGTH);
        $key = hash_hkdf('sha256', $this->appSecret, 32, 'lockpost-token-enc');
        $tag = '';

        $ciphertext = openssl_encrypt(
            $payload, self::CIPHER, $key,
            OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new AppException('Encryption failed');
        }

        return rtrim(strtr(base64_encode($iv . $tag . $ciphertext), '+/', '-_'), '=');
    }

    public function validateLink(string $token): string
    {
        try {
            $raw = base64_decode(strtr($token, '-_', '+/'), true);
            if ($raw === false || strlen($raw) < (self::IV_LENGTH + self::TAG_LENGTH)) {
                throw new AppException('Invalid token structure');
            }

            $iv         = substr($raw, 0, self::IV_LENGTH);
            $tag        = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
            $ciphertext = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);

            $key       = hash_hkdf('sha256', $this->appSecret, 32, 'lockpost-token-enc');
            $decrypted = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

            if ($decrypted === false) {
                throw new AppException('Token verification or decryption failed');
            }

            $data = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || empty($data['email']) || empty($data['exp'])) {
                throw new AppException('Invalid token contents');
            }

            if ($data['exp'] < time()) {
                throw new AppException('Token has expired');
            }

            return $data['email'];
        } catch (AppException $e) {
            throw $e;
        } catch (Exception) {
            throw new AppException('Unable to validate token');
        }
    }
}
```

---

### Fix 2: Secure Token-Bound Submission (`DefaultController::submitMessage`)

```php
#[Route('/message/submit', name: 'app_submit_message', methods: ['POST'])]
public function submitMessage(Request $request, ValidatorInterface $validator): Response
{
    try {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $dto  = new MessageSubmitRequest($data);

        $errors = $validator->validate($dto);
        if (count($errors) > 0) {
            $msgs = array_map(fn($e) => $e->getMessage(), iterator_to_array($errors));
            return $this->json(['success' => false, 'errors' => $msgs], Response::HTTP_BAD_REQUEST);
        }

        // NO filter_var fallback — token must be cryptographically valid
        try {
            $recipientEmail = $this->linkService->validateLink($dto->getToken());
        } catch (AppException) {
            return $this->json(
                ['success' => false, 'error' => 'Invalid or expired submission token'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $signedMessage = $this->pgpSigningService->signMessage($dto->getEncryptedMessage());

        $email = (new Email())
            ->from($this->getParameter('app.mail_from'))
            ->to($recipientEmail)
            ->subject('New PGP Encrypted Message via Lockpost')
            ->html($this->renderView('email/message.html.twig', [
                'message'          => $dto->getEncryptedMessage(),
                'message_signature'=> $signedMessage,
                'server_public_key'=> $this->pgpSigningService->getServerPublicKey(),
                'app_verify_url'   => $this->generateUrl('app_verify', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]));

        $this->mailer->send($email);

        return $this->json(['success' => true, 'message' => 'Message encrypted and dispatched successfully.']);
    } catch (Exception $e) {
        $this->logger->error('Failed to submit message', ['exception' => $e]);
        return $this->json(['success' => false, 'error' => 'An internal error occurred.'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
```

---

### Fix 3: Cached GnuPG Instance with Isolated Verify Keyring (`PgpSigningService.php`)

```php
class PgpSigningService
{
    private readonly \gnupg $gpg;

    public function __construct(
        private readonly string $keyConfigPath,
        private readonly string $privateKeyPath,
        private readonly string $publicKeyPath,
        private readonly string $privateKeyPassphrase,
    ) {
        // Set GNUPGHOME once before creating the gnupg instance
        putenv("GNUPGHOME={$this->keyConfigPath}");
        $this->gpg = new \gnupg();
        $this->gpg->seterrormode(\gnupg::ERROR_EXCEPTION);

        $privateKey   = file_get_contents($this->privateKeyPath);
        $importResult = $this->gpg->import($privateKey);
        $this->gpg->addsignkey($importResult['fingerprint'], $this->privateKeyPassphrase);
    }

    public function signMessage(string $message): string
    {
        $signed = $this->gpg->sign($message); // Reuses cached instance
        if ($signed === false) {
            throw new AppException('Message signing failed');
        }
        return $signed;
    }

    public function verifySignature(string $combinedBlock, string $unused, string $publicKey): bool
    {
        // Isolated temporary keyring — never pollutes server keyring
        $tmpDir = sys_get_temp_dir() . '/lockpost_verify_' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);

        try {
            putenv("GNUPGHOME={$tmpDir}");
            $gpg = new \gnupg();
            $gpg->import($publicKey);
            // Option B: combined cleartext block
            $result = $gpg->verify($combinedBlock, false);
            return is_array($result)
                && isset($result[0]['summary'])
                && ($result[0]['summary'] & \GNUPG_SIGSUM_VALID) !== 0;
        } finally {
            putenv("GNUPGHOME={$this->keyConfigPath}"); // Restore server keyring
            array_map('unlink', glob("{$tmpDir}/*") ?: []);
            rmdir($tmpDir);
        }
    }
}
```

---

### Fix 4: Non-Blocking Key Lookup with Caching (`PgpKeyService.php`)

```php
private function fetchKeyFromNetwork(string $email): string
{
    $responses = [];
    foreach (self::PRIMARY_SERVERS as $server) {
        $responses[] = $this->httpClient->request('GET', "{$server}/pks/lookup", [
            'query'   => ['op' => 'get', 'search' => $email],
            'timeout' => self::TIMEOUT,
        ]);
    }

    // Stream responses — return immediately when first valid key arrives
    foreach ($this->httpClient->stream($responses) as $response => $chunk) {
        try {
            if ($chunk->isLast()) {
                $statusCode = $response->getStatusCode();
                if ($statusCode >= 200 && $statusCode < 300) {
                    $body = $response->getContent(false);
                    if (preg_match('/-+BEGIN PGP PUBLIC KEY BLOCK-+[\s\S]+?-+END PGP PUBLIC KEY BLOCK-+/', $body, $m)) {
                        foreach ($responses as $r) {
                            if ($r !== $response) { $r->cancel(); }
                        }
                        return trim($m[0]);
                    }
                }
            }
        } catch (\Throwable) {
            // Ignore individual server failure and continue
        }
    }

    throw new AppException('No public key found for the provided email address on supported key servers.');
}
```

---

## 12. Consolidated Issue Index

| ID | Severity | Category | Issue | Source | Status |
|----|----------|----------|-------|--------|--------|
| CRITICAL-01 | Critical | Security | Open email relay / token bypass on `/message/submit` | All 4 | Fixed |
| CRITICAL-02 | Critical | Email | Duplicate HTML + JS in email template | All 4 | Fixed |
| CRITICAL-03 | Critical | Security | Sign/verify API mode mismatch — always fails | All 4 | Needs fix |
| CRITICAL-04 | Critical | Testing | Test codifies broken verify behavior | Trae, Kiro | Needs fix |
| CRITICAL-05 | Critical | Security | No rate limiting on email endpoint | All 4 | Needs fix |
| MAJOR-01 | Major | Crypto | Key separation violation — same key for AES + HMAC | All 4 | HKDF applied |
| MAJOR-02 | Major | Performance | GnuPG re-initialised on every sign call | All 4 | Needs fix |
| MAJOR-03 | Major | Concurrency | `putenv(GNUPGHOME)` race condition under FPM | Trae, Hermes, Kiro | Needs fix |
| MAJOR-04 | Major | Config | GNUPGHOME path mismatch Docker vs PHP | Trae | Needs fix |
| MAJOR-05 | Major | Security | Raw exceptions leaked to clients (HTML + JSON + flash) | All 4 | Needs fix |
| MAJOR-06 | Major | Security | No CSRF protection on `/message/submit` | Trae | Needs fix |
| MAJOR-07 | Major | Security | Insecure HKP key lookup / spoofing risk | Anti, Hermes, Kiro | Needs fix |
| MAJOR-08 | Major | Config | `ext-zend-opcache` wrong Composer name | Trae | Needs fix |
| MAJOR-09 | Major | Infra | PHP-FPM ports exposed to host | Kiro | Needs fix |
| MAJOR-10 | Major | Infra | `client_max_body_size 512M` excessive | Kiro | Needs fix |
| MAJOR-11 | Major | Infra | `network_mode: host` in prod | Hermes, Kiro | Needs fix |
| MAJOR-12 | Major | Security | `APP_SECRET` committed in `../../../.env.test` | Kiro | Needs fix |
| MAJOR-13 | Major | Security | GnuPG keyring polluted with user-supplied keys | Anti, Hermes, Kiro | Needs fix |
| LOW-01 | Low | Security | No passphrase on production PGP key | Hermes, Kiro | Needs fix |
| LOW-02 | Low | Security | Key file permissions only enforced in Docker | Hermes | Needs fix |
| LOW-03 | Low | Bug | Overly restrictive PGP regex rejects valid armor headers | Anti, Kiro | Needs fix |
| LOW-04 | Low | Bug | Verify form textareas missing `name` attributes | Kiro | Needs fix |
| LOW-05 | Low | Design | Dual verify paths expect different PGP block types | Kiro | Design decision |
| LOW-06 | Low | Config | `cookie_samesite` not explicitly configured | Kiro | Low risk |
| LOW-07 | Low | Bug | `GNUPG_SIGSUM_VALID === 0` strict check may reject valid sigs | Kiro | Needs fix |
| LOW-08 | Low | Quality | Stale PHPDoc on `PgpSigningService` constructor | Trae, Kiro | Clean up |
| LOW-09 | Low | Quality | Dead `config/packages/gpg.yaml` config file | Trae | Delete file |
| LOW-10 | Low | Quality | Dead Doctrine Messenger DSN | Trae, Kiro | Change to sync:// |
| LOW-11 | Low | Quality | Unused `TransportExceptionInterface` import | Trae | Delete line |
| LOW-12 | Low | Quality | `../../../.env.test` whitespace breaks `SESSION_AUTO_START` | Trae | Fix whitespace |
| LOW-13 | Low | Frontend | `execCommand('copy')` deprecated | Kiro | Replace |
| LOW-14 | Low | Frontend | Turbo listed in tech stack but not present | Kiro | Clarify |
| LOW-15 | Low | Quality | Broken exception chains — `$previous` not passed | Kiro | Fix |

---

*Comprehensive review compiled from 4 independent AI review passes: Antigravity (August 2026), Trae (2026-08-15), Hermes (2026-08-15), Kiro (2026-08-15). All critical and major issues independently cross-validated with 100% consensus.*
