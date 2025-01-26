# PGP Reply Symfony Application

### About

PGP Reply is a secure web application that allows users to generate unique links for receiving PGP-encrypted messages. The application is built with Symfony and provides a simple way for people to send you encrypted messages without needing to understand the complexities of PGP encryption.

### Why

The app solves a common problem: receiving sensitive information securely from people who aren't familiar with PGP encryption. By generating a unique link that you can share, anyone can send you encrypted messages that only you can read, without needing to understand the technical details of PGP.

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
- **Server-Side Signing**: The server signs the encrypted content (experimental feature)

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

The application uses MailHog for testing email delivery in the development environment. To verify the email delivery process:

1. Access the MailHog web interface at http://localhost:8025
2. Send a test message through your application
3. Check the MailHog interface to see:
   - The encrypted message content
   - Recipient email address
   - Email headers and metadata

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

### Contributing

Contributions are welcome! Please feel free to submit pull requests or open issues for any bugs or feature requests.

Before submitting changes:
1. Make sure all tests pass
2. Update documentation as needed
3. Follow the existing code style and conventions