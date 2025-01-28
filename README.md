# PGP Reply Symfony Application

### About

PGP Reply is a secure web application that allows users to generate unique links for receiving PGP-encrypted messages. The application is built with Symfony and provides a simple way for people to send you encrypted messages without needing to understand the complexities of PGP encryption.

### Why

The app solves a common problem: receiving sensitive information securely from people who aren't familiar with PGP encryption. By generating a unique link that you can share, anyone can send you encrypted messages that only you can read, without needing to understand the technical details of PGP.



### What's PGP?

PGP (Pretty Good Privacy) is an encryption technology used for:
- Encrypting sensitive messages and files
- Digital signatures
- Secure communication

The application uses PGP's public-key cryptography to ensure that only the intended recipient can read the messages. To use the application, you need to:

1. Have a PGP key pair (public and private keys)
2. Publish your public key on common key servers
3. Keep your private key secure and never share it

You can learn more about generating and managing PGP keys on the application's help page.


### How It Works

1. You generate a unique link through the app by providing your email address
2. The app verifies your public PGP key from common key servers
3. Share the generated link with someone who needs to send you sensitive information
4. When they visit the link, they can type their message in a secure form
5. The message is encrypted in their browser using your public PGP key
6. The encrypted message is sent to your email address
7. You can decrypt the message using your private PGP key

### Security Features

- **End-to-End Encryption**: All messages are encrypted in the browser using OpenPGP.js before being sent
- **Zero Storage**: No messages are stored on the server - they are only forwarded to your email
- **Client-Side Encryption**: Messages are encrypted on the sender's browser using your public key

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

### Server-Side Message Signing

To enhance security and message authenticity, the application implements server-side PGP signing of all outgoing encrypted messages. This feature provides several benefits:

- **Message Authentication**: Recipients can verifySignaturePage that messages were actually processed by our server
- **Tampering Detection**: Any modifications to the message during transit can be detected
- **Trust Chain**: Creates a verifiable chain of trust from sender through our service to recipient

#### Technical Implementation

The signing process is handled by the `PgpSigningService` and involves these key components:

1. **Server Key Management**:
   - Server maintains its own PGP key pair in the `config/pgp/` directory
   - Private key is securely stored and used only for message signing
   - Public key is freely available via the `/public-key` endpoint

2. **Signing Process**:
   - Encrypted messages are signed using the server's private key
   - Signing occurs after browser-side encryption but before email delivery
   - Implementation uses GnuPG through secure PHP bindings

3. **Security Considerations**:
   - Server's private key is protected and never exposed to users
   - Signing process occurs in isolated environment
   - Regular key rotation policies are enforced

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

### Contributing

Contributions are welcome! Please feel free to submit pull requests or open issues for any bugs or feature requests.

Before submitting changes:
1. Make sure all tests pass
2. Update documentation as needed
3. Follow the existing code style and conventions
