# SYM.PGP.ONY

### About

SYM.PGP.ONY is a secure web application that allows users to generate links that can be shared with pople who can reply back in a confidential manner.

The application is built with Symfony and provides a simple way for people to send you encrypted messages without needing to understand the complexities of encryption.

### Why

The app solves a common problem for some: receiving sensitive information securely from people who aren't familiar with the ways of doing so.
By generating a unique secure link that you can share, anyone can send you PGP encrypted messages that only you can read, without the parties needing to understand the technical details.


### How It Works

1. You generate a unique secure link through the app by providing your email address
2. The app verifies your public PGP key from common key servers
3. Share the generated link with someone who needs to send you sensitive information
4. When they visit the link, they can type their message in a secure form
5. The message is encrypted in their browser using your public PGP key
6. The encrypted message is submitted to the server where its being signed and forwarded to your email address
7. You can decrypt the message using your private PGP key
8. You can check the signature of the server, so that you are sure the messge was not tampered with and that is was not sent by someone else.

### What's PGP?

PGP (Pretty Good Privacy) is an encryption technology used for:
- Encrypting sensitive messages and files
- Digital signatures
- Secure communication

The application uses mainly PGP with less other cryptography, to ensure that the communication is secure, having the end goal of the recipient being to receive, prove and read the messagess securely.

You can learn more about generating and managing PGP keys on the application's help page.
Further more, you can also get server's key meterial, prove messages, signatures and experiment with PGP, separate from the app usage.



### Security Features

- **End-to-End Encryption**: All messages are encrypted while in transit over insecure channels
- **Client-Side Encryption**: Messages are encrypted on the sender's browse
- **Server-Side Signing** The application implements server-side PGP signing of all outgoing encrypted messages before forwarding them
- **Best tech** The latest cryptography is used to enrypted messages, using the public key that was  decoded from the shared link before being sent the server
- **Zero Storage**: No messages are stored on the server - they are signed with its keys first then forwarded to your mailbox
- **No tracking** The server keeps the logs in memory, not on the fylesystem, it does not store any information at all, no database, no caching, no sessions
- **No cookies** No analytics, no data, there is no legal need for a privacy policy.
- **No anti-features** Simple design and straight farward usage. No need for guidance, no banners to close, no fancy or extra features.
- **No licence** Free and open source software that you can check, hack and self host.
- **Free as in Liberty** Made in the spirit and inspiration #cyberpunks manifesto


### Server-Side Message Signing

To enhance security and message authenticity, the application implements server-side PGP signing of all outgoing encrypted messages. This feature provides several benefits:

- **Message Authentication**: Recipients can verifySignaturePage that messages were actually processed by our server
- **Tampering Detection**: Any modifications to the message during transit can be detected
- **Trust Chain**: Creates a verifiable chain of trust from sender through our service to recipient

using OpenPGP.js key rotations
CRFT tokens and OWASP protections

**Security Considerations**:
   - The server's private key is protected and never exposed to users
   - The signing process occurs in isolated environment
   - Regular key rotation policies are enforced
   - The shared links are generated in the most secure way on the server possible and have an expiration date
   - The information used by the server to generate an encryption token is already willingly available in public

#### Technical Implementation

using OpenPGP.js

The signing process is handled by the `PgpSigningService` and involves these key components:

1. **Server Key Management**:
   - Server maintains its own PGP key pair in the `config/pgp/` directory
   - Private key is securely stored and used only for message signing
   - Public key is freely available via the `/public-key` endpoint

2. **Signing Process**:
   - Encrypted messages are signed using the server's private key
   - Signing occurs after browser-side encryption but before email delivery
   - Implementation uses GnuPG through secure PHP bindings

### Requirements
For the initiator of the communication to use the application it needs to:

1. Have a PGP key pair
2. Publish your public key on common key servers
3. Securely keep your private key in order the decrypt the message being received.

### Development Setup

The application uses Docker for development. To get started:

1. Clone the repository
2. Make sure you have Docker and Docker Compose installed
3. Run the following command to start the development environment:
```bash
docker-compose up -d
```

This will start:
- NGINX web server (ports 80, 443)
- PHP-FPM (ports 9000, 9001)
- MailHog for email testing (ports 1025, 8025)

### Testing Email Delivery

The application uses MailHog for testing email delivery in the development environment. To verifySignaturePage the email delivery process:

1. Access the MailHog web interface at http://localhost:8025
2. Send a test message through your application
3. Check the MailHog interface to see:
   - The encrypted message content
   - Recipient email address
   - Email headers and metadata


#### Configuration

The signing feature requires proper setup in your deployment:

1. Generate a server key pair:
```bash
gpg --gen-key
```

2. Configure key paths in `config/packages/gpg.yaml`:
```yaml
app:
    gpg:
        public_key_path: '%kernel.project_dir%/config/pgp/public.key'
        private_key_path: '%kernel.project_dir%/config/pgp/private.key'
```

3. Set appropriate permissions:
```bash
chmod 600 config/pgp/private.key
chmod 644 config/pgp/public.key
```

#### Implementation Challenges

During development, several challenges were addressed:

1. **Key Management**:
   - Secure key storage in different environments
   - Key permission management in Docker containers
   - Automated key rotation procedures

2. **Performance**:
   - Optimizing signing process for minimal latency
   - Handling concurrent signing operations
   - Memory management for large messages

3. **Error Handling**:
   - Graceful handling of signing failures
   - Clear error messages for debugging
   - Fallback procedures for system issues

#### Alternative Approaches Considered

1. **Client-Side Signing**:
   - Considered having sender sign messages
   - Rejected due to complexity for users
   - Would have required client-side key management

2. **Blockchain Verification**:
   - Evaluated using blockchain for message verification
   - Deemed unnecessarily complex for requirements
   - Would have added significant overhead

3. **HSM Integration**:
   - Considered Hardware Security Modules
   - May be implemented in future versions
   - Currently unnecessary for security requirements



---

Future Enhancements

The following features are planned for future releases:
1. HSM (Hardware Security Module) Integration:
   - Enhance security by using hardware-based key storage.
   - Improve performance for high-volume message signing.

2. Mobile App Support:
   - Develop a mobile-friendly interface.
   - Add support for push notifications and QR code-based secure link sharing.

3. Advanced Analytics:
   - Provide optional analytics for self-hosted instances.
   - Include message delivery statistics and error reports.

4. Localization:
   - Add support for multiple languages.
   - Allow users to contribute translations.

5. Automatic Key Management:
   - Implement automated key rotation.
   - Notify users of upcoming key expiration dates.

---

Known Limitations

While the application is robust and secure, there are some limitations:
1. Dependency on Public Key Servers:
   - If a key server is unavailable, users may face delays in link generation.
   - Future versions may include a fallback mechanism.

2. Browser-Side Encryption:
   - Relies on the user's browser to perform encryption.
   - Users with outdated browsers may experience compatibility issues.

3. Email Delivery:
   - Relies on the configured SMTP server for email delivery.
   - Ensure your server is properly configured to avoid spam filtering.

---

Support

If you encounter any issues or have questions, feel free to:
- Open an issue on our GitHub repository.
- Join the community discussion on our forums.
- Reach out via email for direct support.

---

### Contributing

Contributions are welcome! Please feel free to submit pull requests or open issues for any bugs or feature requests.

Before submitting changes:
1. Make sure all tests pass
2. Update documentation as needed
3. Follow the existing code style and conventions
