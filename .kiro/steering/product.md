# Product: SYM.PGP.ONY

A secure web application that lets users receive PGP-encrypted messages via shareable links. It solves the problem of securely receiving sensitive information from people who aren't familiar with encryption.

## Core Workflow

1. User enters their PGP-associated email to generate a unique, time-limited link
2. System verifies a public PGP key exists for that email on public key servers
3. User shares the link with whoever needs to send them a message
4. Recipient opens the link, types a message — it's encrypted client-side in the browser using the sender's public key
5. Server signs the encrypted message and emails it to the original user
6. User decrypts with their private PGP key; can verify the server signature for authenticity

## Key Design Principles

- Zero storage: no messages are persisted server-side
- No tracking or cookies
- Client-side encryption only (OpenPGP.js)
- Stateless token-based links (AES-256-CBC + HMAC-SHA256, 30-day expiry)
- Server signs outgoing messages with its own PGP key for authenticity verification
