# Roadmap

Pending and partially completed work from the technical review and audit.

## Pending — Not Started

| ID | Item | Notes |
|----|------|-------|
| 4.1 | Migrate `TokenLinkService` from AES-256-CBC + HMAC-SHA256 to AES-256-GCM | HKDF is already in place; this is the remaining AEAD upgrade |
| 4.3 | Add `bin/console app:pgp:check` command | Should validate key presence, permissions, and passphrase at deploy time |
| 4.4(a) | Add `Makefile` or `bin/dev` helper script | Wrap repeated Docker/test commands for DX |
| 4.4(c) | Add MailHog healthcheck | Avoid startup race between PHP and MailHog |
| 4.4(e) | Skip cache clear on production container boot | Warm cache at build time instead |

## Partially Done — Needs Completion

| ID | Item | Current State | What Remains |
|----|------|---------------|--------------|
| 2.5 | Stream keyserver responses and cancel slower requests | Concurrent requests are issued, but responses are consumed linearly with `getContent()` before cancellation | Replace with `HttpClientInterface::stream()` short-circuit on first valid key |
| 2.11 | Themed error pages via Twig | Status codes now distinguish 400 vs 500 | Replace raw HTML in `ErrorHandler` with a Twig template extending `base.html.twig` |
| 4.2 | Prioritize verified keyserver + show fingerprint | `keys.openpgp.org` is first in the list | Add UID verification for fallback servers and display key fingerprint on the submit page |
| 4.4(d) | Replace deprecated `document.execCommand('copy')` | Clipboard controller still uses it as a fallback | Remove deprecated fallback and show manual-copy instructions instead |
| 4.4(f) | Verify Composer installer integrity in Docker | Dockerfile already uses multi-stage copy from `composer:2` | Document this choice in README if not already noted |

## Blocked / Cannot Verify

| ID | Item | Reason |
|----|------|--------|
| 2.9 | `.env.test` whitespace and committed secret rotation | ✅ **Resolved** — fixed via git history read + targeted patch (cannot read file directly, but diff confirms applied) |

## Future Improvements

- Key caching layer via Symfony Cache (`cache.app`) for public keys
- Integration test for full submit flow: encrypt, sign, send email
- Rate-limiting and abuse edge-case tests
- UI polish: typographic hierarchy, micro-interactions, fingerprint badges
- CSP nonce/hash audit for stronger browser crypto controls
