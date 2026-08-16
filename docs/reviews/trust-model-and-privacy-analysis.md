# Trust Model and Privacy Analysis — Lockpost Security Deep Dive

**Date:** 2026-08-16  
**Scope:** Deep inspection of the cryptographic trust model, PGP signature validation, privacy guarantees, and operational security risks in Lockpost (`sym-pgp-ony`).  
**Source:** Code inspection of `src/Service/PgpSigningService.php`, `src/Service/PgpKeyService.php`, `src/Service/TokenLinkService.php`, `src/Controller/DefaultController.php`, `config/services.yaml`, and `src/Exception/ErrorHandler.php`.

---

## TL;DR

Lockpost is **cryptographically sound but operationally assumes a trusted server**. It protects message *content* via end-to-end PGP encryption and binds submissions to tokens via AES-256-CBC + HMAC-SHA256 with HKDF, but it provides **no protection against a malicious or compromised server operator**. Server-side logs, session data, and temp directories can leave traces of recipient emails and verification metadata.

---

## 1. What Makes a Public Key Trustworthy?

### Trust Model: **No web-of-trust. Trust on first use by keyserver reputation.**

There is no certificate authority or web-of-trust validation in this codebase. The trust anchor is the keyserver ranking in `PgpKeyService.php`:

```php
private const KEY_SERVERS = [
    'https://keys.openpgp.org',      // ✅ Email-verified
    'https://keyserver.ubuntu.com',  // ❌ Unverified uploads allowed
    'https://pgp.mit.edu',           // ❌ Unverified uploads allowed
];
```

### How trust is established at link-generation time:

1. User enters a recipient email on the homepage.
2. `PgpKeyService::verifyPublicKeyExists()` fires concurrent `HKP` requests to all three servers.
3. The **first** server to return a `BEGIN PGP PUBLIC KEY BLOCK` wins — regardless of whether its UID matches the requested email.
4. No UID verification is performed. No key fingerprint is displayed to the sender for manual confirmation.

### Risk: Key substitution via HKP spoofing

An attacker who uploads a key with `victim@example.com` as the UID to `keyserver.ubuntu.com` could have that key returned to the sender if that server responds before `keys.openpgp.org`. The sender would then encrypt to the attacker's key, and the attacker could read the message.

### Mitigation status:
- ✅ `keys.openpgp.org` (email-verified) is queried first
- ❌ No UID matching on fallback servers
- ❌ No key fingerprint displayed on submit page for manual verification

### Recommendation:
Add UID verification for keys returned by non-verified keyservers, and display the resolved key fingerprint on the submit page for sender confirmation.

---

## 2. What Makes a PGP Signature Valid?

### Verification model: **Signature matches provided key (not key trustworthiness)**

The server-side verification path is `PgpSigningService::verifySignature()`:

```php
public function verifySignature(string $signedMessage, string $publicKey): bool
{
    $tmpHome = rtrim(sys_get_temp_dir(), '/') . '/gpg_' . bin2hex(random_bytes(8));
    mkdir($tmpHome, 0700, true);

    try {
        putenv("GNUPGHOME={$tmpHome}");
        $gpg = new gnupg();
        $keyInfo = $gpg->import($publicKey);  // User-supplied key, not keyserver lookup
        
        $info = $gpg->verify($signedMessage, false);  // false = combined cleartext block
        
        foreach ($info as $sig) {
            if (($sig['summary'] & GNUPG_SIGSUM_RED) === 0) {
                return true;  // No RED bit = valid signature
            }
        }
        return false;
    } finally {
        putenv("GNUPGHOME={$this->keyConfigPath}");
        $this->removeTempDir($tmpHome);
    }
}
```

### What "valid" means here:
- The signature cryptographically matches the **user-provided** public key.
- The `GNUPG_SIGSUM_RED` bit (value 4) indicates cryptographic invalidity — if it's not set, GnuPG considers the signature valid.
- **This does NOT verify key ownership.** The user pasting both the key and the signed message controls both sides.

### Trust boundaries:
- **Server-signed messages** (from `/message/submit`) can be verified against the server's public key. The recipient must independently confirm the server's public key fingerprint — delivered via email template or `/server-key` download endpoint.
- **User verification** (via `/verify/signature` form) is a client-assisted tool — the user is responsible for obtaining the correct public key.

### Key isolation:
- ✅ Uses isolated temporary keyring per verification call
- ✅ Temp keyring is cleaned in `finally` block
- ✅ No pollution of server's signing keyring

---

## 3. What Privacy Guarantees Are Actually Provided?

### What IS protected:

| Asset | Protection | Mechanism |
|-------|-----------|-----------|
| **Message plaintext** | Strong | Encrypted client-side with OpenPGP.js using recipient's public key before HTTP transmission |
| **Token contents** | Strong | AES-256-CBC encryption with HMAC-SHA256 authentication |
| **Token-key separation** | Strong | HKDF with distinct info strings (`lockpost-token-enc`, `lockpost-token-auth`) |
| **Token tampering** | Strong | `hash_equals()` constant-time comparison |
| **Token expiry** | Strong | Embedded `exp` timestamp, rejected by `validateLink()` |
| **Message persistence** | Strong | No database; messages only exist in memory/queue transiently |

### What is NOT protected:

| Asset | Risk | Mechanism |
|-------|------|-----------|
| **Sender IP address** | Logged | Symfony Monolog writes `$request->getClientIp()` to error logs |
| **Recipient email** | Recoverable | If `APP_SECRET` is compromised, all link tokens can be decrypted to reveal recipient emails |
| **Key lookup metadata** | Observable | Server makes outbound HTTP to keyservers; source IP reveals sender activity patterns |
| **Verification session data** | Stored | `last_verification_result` stored in user's session (file-based by default) |
| **PGP key material in sessions** | Stored | Flash messages contain sanitized but server-side stored PGP blocks |

### The core assumption:
Lockpost assumes the **server operator is trusted**. The cryptographic design protects against network eavesdropping and passive observers, but a malicious operator with access to `APP_SECRET`, PGP keys, logs, or the session store can reconstruct the full communication chain.

---

## 4. Operational Logs and Secrets That Undermine "Leave No Trace"

### Server-side secrets at risk:

1. **`APP_SECRET`** (via `%env(APP_SECRET)%` in `TokenLinkService`)
   - If leaked, all link tokens can be decrypted to reveal recipient emails.
   - Currently set via environment variable — not hardcoded in repo (verified after MEDIUM-03 fix), but could be exposed in:
     - Docker inspect output
     - Kubernetes/Helm configs
     - CI/CD environment variables

2. **PGP private key passphrase** (via `%env(PGP_PRIVATE_KEY_PASSPHRASE)%`)
   - Enforced non-empty in `prod`/`test` environments via `PgpSigningService` constructor guard.
   - If passed as a CLI argument or visible in `ps` output, leaked.
   - If the key file at `config/pgp/private.key` is accessible on the host (bind mount), the passphrase is the only protection.

3. **SMTP credentials** (via `MAILER_DSN`)
   - Sent via Symfony Mailer. If Monolog logs at DEBUG level, SMTP connection logs may include server hostnames or error responses.
   - The `submitMessage()` catch block logs full exception context:
     ```php
     $this->logger->error('Failed to submit message: ' . $e->getMessage(), ['exception' => $e]);
     ```
     This captures the full `Throwable` object, which may include SMTP response bodies.

### Log file vulnerabilities:

Two distinct logging sinks expose sensitive information:

1. **`DefaultController::submitMessage()` (line 245):**
   ```php
   $this->logger->error('Failed to submit message: ' . $e->getMessage(), ['exception' => $e]);
   ```
   Logs the raw exception message, which could contain:
   - GnuPG diagnostic output (file paths, key fingerprints)
   - SMTP server responses (hostnames, error codes)
   - OpenSSL error strings (could reveal key material indirectly)

2. **`DefaultController::verifyIsValidSignature()` (line 315):**
   ```php
   $this->logger->error('Signature verification error: ' . $e->getMessage());
   ```
   Logs GnuPG error messages that may include key fingerprints or file paths from the user-supplied public key import.

3. **`ErrorHandler::handleControllerException()`:**
   Logs `$e->getMessage()` — but since all user-facing exceptions are now wrapped in `AppException` with fixed messages, this is less likely to leak sensitive data. Still, the full `$e` object is logged in the controller catch blocks.

### Session data persistence:

```php
// DefaultController.php, line 313
$request->getSession()->set('last_verification_result', $isValid);
```

- Session data is written to `var/sessions/` (default file handler) and persists on disk.
- Flash messages are stored in the session and survive page reloads.
- If the session handler is file-based, PGP key material and signed message blocks are written to disk in plaintext.

### Temp directory artifacts:

```php
// PgpSigningService.php, line 144
$tmpHome = rtrim(sys_get_temp_dir(), '/') . '/gpg_' . bin2hex(random_bytes(8));
```

- Uses `sys_get_temp_dir()` which resolves to `/tmp` on Linux.
- In a container, this is typically a tmpfs volume — cleared on container restart.
- **However**, if the PHP process is killed (SIGKILL, OOM) mid-execution, the `finally` block never runs, and temp keyrings persist with imported user-supplied public keys.
- On shared hosts, `/tmp` may be accessible to other users/processes.

### Cache artifacts:

- Symfony's cache (`var/cache/`) stores compiled service definitions, container parameters, and potentially serialized environment variables.
- If `%env(PGP_PRIVATE_KEY_PASSPHRASE)%` is resolved and cached in the compiled container, the passphrase may persist in `var/cache/prod/appProdProjectContainer.php` or similar.
- This is a known Symfony pattern — environment variables are resolved once and cached.

### Network-level traces:

- Outbound HTTP requests to `keys.openpgp.org`, `keyserver.ubuntu.com`, and `pgp.mit.edu` reveal which email addresses are being looked up.
- SMTP delivery via MailHog (dev) or production MTA creates mail server logs containing recipient email addresses and message IDs.

---

## Summary: The Trust Chain

```
Sender → [HTTPS] → Lockpost Token Endpoint → [Token validated, AES-256-CBC+HMAC/HKDF]
       → [PGP key lookup via HKP] → [Sign with Server RSA-4096 key]
       → [SMTP to recipient mailbox] → [Recipient decrypts locally with PGP]
       → [Optional server signature verification]

Trust assumptions at each hop:
1. HTTPS — assumes TLS is not MITM'd (needs HSTS — not implemented)
2. Token — assumes APP_SECRET is secret (single point of failure)
3. HKP — assumes keys.openpgp.org email verification is correct (no UID check in app)
4. Server key — recipient must independently verify fingerprint (no TOFU/WoT in app)
5. SMTP — assumes mail server integrity (no DANE/MTA-STS)
6. Verification — user provides both key and signature (trust on first use)
```

The system is well-engineered cryptographically but relies entirely on the honesty and competence of the server operator and mail delivery chain.
