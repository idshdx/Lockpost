# SYM.PGP.ONY

### What

SYM.PGP.ONY is a secure web application
that allows users
to generate links that can be shared with people who will reply to you with private messages.

### Why

The app solves a common problem for some: receiving sensitive information securely from people who aren't familiar with the ways of doing so.

By generating a unique secure link that you can share, anyone can send you PGP encrypted messages that only you can 
read, without the other party needing to understand the technical details of encryption.


### How

1. You generate a unique secure link through the app by providing your email address
2. The app verifies your public PGP key from common key servers
3. Share the generated link with someone who needs to send you sensitive information
4. When they visit the link, they can submit their message
5. The message is encrypted in their browser using your public PGP key
6. The encrypted message is submitted to the server where its being signed and forwarded to your email address
7. You can decrypt the message using your private PGP key
8. You can check the signature of the server, so that you are sure the message was not tampered with and that is not sent by someone else.

###  Features

- **End-to-End Encryption**: All messages are encrypted while in transit over insecure channels
- **Client-Side Encryption**: Messages are encrypted on the sender's browser
- **Server-Side Signing** The application implements server-side PGP signing of all outgoing encrypted messages before forwarding them
- **Best tech** The latest cryptography is used to encrypt messages, using the public key decoded from the shared link before being sent to the server
- **Zero Storage**: No messages are stored on the server—they are signed with its keys first then forwarded to your mailbox
- **No tracking** The server keeps the logs in memory, not on the filesystem* , it does not store any information at 
  all, no database, no caching, no sessions*
- **No cookies** No analytics, no data, there is no legal need for a privacy policy.
- **No anti-features** Simple design and straight forward usage. No need for guidance, no banners to close, no fancy or extra features.
- **No license** Free and open source software that you can check, hack and self-host.
- **Free as in Liberty** Made in the spirit and goals closely aligned with the cypherpunk's
  manifesto: https://www.activism.net/cypherpunk/manifesto.html



#### Security Considerations
   - The server's private key is protected and never exposed to users
   - The signing process occurs in an isolated environment
   - Regular key rotation policies are enforced
   - The shared links are generated in the most secure way on the server possible and have an expiration date
   - The information used by the server to generate an encryption token is already willingly available in public

To enhance security and message authenticity, the application implements server-side PGP signing of all outgoing
encrypted messages.
This feature provides several benefits:
- **Message Authentication**: Recipients can verifySignaturePage that our server actually processed messages
- **Tampering Detection**: Any modifications to the message during transit can be detected
**Secure** Latest advanced cryptography possible in high regard to its implementation
- **Error logging** its general to avoid side attacks, or any other unintended leaks
- **Rate limiting** on the server to avoid overloading or other DDoS attack types
- **Trust Chain**: Creates a verifiable chain of trust from sender through our service to recipient
  using OpenPGP.js key rotations, CRFT tokens, and OWASP protections.

### Requirements
For the initiator of the communication to use the application, it needs to:

1. Have a PGP key pair
2. Publish your public key on common key servers
3. Securely keep your private key to decrypt the message being received.

Based on the provided implementation and available documentation, here is a detailed technical review of the system, its
architecture, implementation components, and associated considerations:

### What's PGP?

PGP (Pretty Good Privacy) is an encryption technology used for:

- Encrypting sensitive messages and files
- Digital signatures
- Secure communication

The application mainly uses PGP with less other cryptography, to ensure that the communication is secure,
having the end goal of the recipient being to receive, prove and read the messages securely.

You can learn more about generating and managing PGP keys on the application's help page.
Furthermore, you can also get server's key material, prove messages, signatures and experiment with PGP,
separate from the app usage.
---

## **Technical Documentation**

### **Overview**

**SYM.PGP.ONY** is a Symfony-based web application that provides a simple, lightweight, and secure solution for sharing
Public-Key PGP encrypted messages.

The guiding principle of SYM.PGP.ONY is to **simplify encryption** without compromising security, offering:

- **User-friendly interface and workflows** for generating encryption links.
- **Stateless server design** focusing on zero storage with ephemeral processing.
- **Strong cryptographic protections** guided by the newest encryption algorithms and principles.

---

### **System Architecture & Flow**

#### Workflow:

1. **Initiator's Interaction:**
   - The user enters their email (associated with a PGP public key on known keyserver).
   - A unique **tokenized link** is generated and shared.

2. **Recipient's Action:**
   - Use the provided token link to write their confidential message on the web interface.
   - The message is encrypted using the initiator’s **PGP public key** (performed in their browser for zero-leak
     design).

3. **Server Role:**
   - Validates the tokenized link and forwards the ciphertext.
   - **Signs the encrypted message** using a server-held private key.

4. **Delivery:**
   - Signed ciphertext is sent to the initiator's email address.

5. **Verification:**
   - End-users can validate the signed PGP messages using the server's public key, ensuring integrity and
     authentication.

### **Technical Components**

#### **Core Services**

1. **TokenLinkService**
   - Generates secure, time-sensitive token-based links for communication initiation.
   - Internals:
      - **Algorithm:** Uses AES-256-CBC for encryption.
      - **HMAC Authentication:** Prevents token tampering with SHA-256 HMAC.
   - Features:
      - Link expiration is enforced (default to 30 days).
      - Use the application secret for encryption.

2. **PgpKeyService**
   - Fetches and verifies PGP public keys from trusted public key servers.
   - Interfaces with supported key servers:
      - `https://keys.openpgp.org`
      - `https://keyserver.ubuntu.com`
      - `https://pgp.mit.edu`
   - Provides failover mechanisms for unavailable servers or network issues.

3. **PgpSigningService**
   - Manage the server's private PGP key for signing outgoing messages.
   - Operations include:
      - **Signing:** Uses the server's private key.
      - **Verification:** Validates signatures by matching with public keys.
   - Secure GnuPG (gnupg) bindings ensure robust cryptographic standards.

#### **Controller Logic**

Controllers use Symfony's best practices for routing, validation, and user interaction.

- **index.html.twig (Link Generation):**
   - Collects email input for validation against public PGP servers.
   - Generates a tokenized link post-validation.

- **submit.html.twig (Submit Encrypted Message):**
   - Displays the initiator's PGP public key.
   - Provides a secure form for encrypting the recipient's message in the browser using **OpenPGP.js**.

- **message.html.twig (Email Delivery):**
   - Custom plaintext email templates for secure delivery of encrypted messages.
   - Includes PGP signature, message, and server's public key.

- **verify.html.twig (Signature Verification):**
   - Provides a step-by-step guide for validating message integrity.

#### **Email Delivery**

- Dependencies: Symfony Mailer.
- Testing Environment: Uses **MailHog** for sandbox testing during development.
- Production Flow:
   - **From Address:** Configurable (recommended: dedicated no-reply@domain email).
   - Delivery via SMTP with signed/encrypted messages.

#### **OpenPGP Integration**

Encryption is handled entirely on the client-side using **OpenPGP.js**, ensuring:

- Zero exposure of plaintext to the server.
- Cross-browser compatibility.

---

### **Security Considerations**

- **Cryptographic Best Practices:**
   - AES-256-CBC for symmetric encryption.
   - SHA-256 HMAC for token authentication.
   - PGP signing and verification for email authenticity.

- **Statelessness:**
   - Eliminates risks of user data leakage from memory, logs, or persistent storage.

- **Server Privilege Protection:**
   - Private key storage and usage are restricted and configurable.
   - Suitable configuration of file and folder permissions ensures no unauthorized access.

- **Rate Limiting:**
   - Prevents brute force and DDoS attacks against the link generation or validation endpoints.

- **Token Expiry:**
   - Each generated link has a time-bound expiration for limited exposure.

---

### **Development Guidance**

1. **Environment Setup**:
   - **Docker-based Deployment**:
      - Run NGINX, PHP-FPM, and MailHog via:

```shell script
docker-compose up -d
```

- Ensure adequate security hardening for private key storage:

```shell script
chmod 600 config/pgp/private.key
```

1. **Configuration Example**:
   - `config/packages/gpg.yaml`:

```yaml
app:
         gpg:
             public_key_path: '%kernel.project_dir%/config/pgp/public.key'
             private_key_path: '%kernel.project_dir%/config/pgp/private.key'
```

1. **Testing:**
   - Run Symfony tests (`phpunit`) for validating business logic.
   - Verify email flow using MailHog interface: `http://localhost:8025`.

2. **Known Limitations**:
   - **Public Key Server Dependencies:**
      - Downtime in key servers could delay link generation.
        _Planned Improvement_: Adopt a fallback mechanism.
   - **Browser Compatibility:**
      - Encryption relies on modern browser standards (e.g., WebCrypto API).

3. **Future Enhancements**:
   - Integration of hardware security modules (HSM) for private key handling.
   - Extending localization for multilingual support.
   - Mobile-friendly UI for generating and verifying links.

---

### **Challenges Faced**

1. **Key Management:**
   - Secure multienvironment key rotation was a focal point of development to balance usability and protection.

2. **Latency:**
   - Optimizing cryptographic operations for performance involved streamlining browser-side JavaScript encryption and
     backend signing operations.

3. **Error Handling:**
   - Graceful user feedback for potential issues, e.g., expired tokens or missing public keys was enhanced with clear
     exception handling and logging.

---

### **Summary**

The application demonstrates a robust implementation of a PGP messaging relay system with the following high-value
propositions:

- **Ease of Use**: Simplified processes for non-technical audiences to generate secure communication links.
- **Security**: Leveraging modern cryptography while maintaining statelessness.
- **Extensibility**: Modular design supports future enhancements with minimal refactoring.

Verification, encryption, and messaging design align with OpenPGP standards, making SYM.PGP.ONY a viable solution for
privacy-first communication systems.

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

1. Configure key paths in `config/packages/gpg.yaml`:
```yaml
app:
    gpg:
        public_key_path: '%kernel.project_dir%/config/pgp/public.key'
        private_key_path: '%kernel.project_dir%/config/pgp/private.key'
```

1. Set appropriate permissions:
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
   - Optimizing a signing process for minimal latency
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
   - It Would have added significant overhead

3. **HSM Integration**:
   - Considered Hardware Security Modules
   - It May be implemented in future versions
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
- Open an issue in our GitHub repository.
- Join the community discussion on our forums.
- Reach out via email for direct support.

---

### Contributing

Contributions are welcome! Please feel free to submit pull requests or open issues for any bugs or feature requests.

Before submitting changes:
1. Make sure all tests pass
2. Update documentation as needed
3. Follow the existing code style and conventions
