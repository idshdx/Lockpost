<?php

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

class MessageSubmitRequest
{
    #[Assert\NotBlank(message: 'Encrypted message cannot be blank')]
    #[Assert\Type('string')]
    #[Assert\Regex(
        pattern: '/^-----BEGIN PGP MESSAGE-----[\s\S]+-----END PGP MESSAGE-----$/',
        message: 'Invalid PGP encrypted message format'
    )]
    private string $encrypted;

    #[Assert\NotBlank(message: 'Token or recipient is required')]
    #[Assert\Type('string')]
    private string $token;

    #[Assert\Type('string')]
    private string $recipient;

    /**
     * The resolved recipient email address (set by the controller after token validation).
     */
    private ?string $recipientEmail = null;

    public function __construct(array $data)
    {
        $this->encrypted = trim($data['encrypted'] ?? $data['encryptedMessage'] ?? '');
        $this->token = trim($data['token'] ?? $data['recipient'] ?? '');
        $this->recipient = trim($data['recipient'] ?? '');
    }

    public function getEncryptedMessage(): string
    {
        return $this->encrypted;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    /**
     * Returns the resolved recipient email address after token validation.
     * Falls back to the raw recipient from the request if not yet resolved.
     */
    public function getRecipientEmail(): string
    {
        return $this->recipientEmail ?? $this->recipient;
    }

    /**
     * Sets the resolved recipient email address after token validation.
     */
    public function setRecipientEmail(string $email): void
    {
        $this->recipientEmail = $email;
    }
}
