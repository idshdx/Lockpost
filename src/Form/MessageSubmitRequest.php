<?php

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

class MessageSubmitRequest
{
    #[Assert\NotBlank]
    #[Assert\Type('string')]
    private string $encrypted;

    #[Assert\NotBlank]
    #[Assert\Email]
    private string $recipient;

    public function __construct(array $data)
    {
        $this->encrypted = $data['encrypted'] ?? '';
        $this->recipient = $data['recipient'] ?? '';
    }

    public function getEncryptedMessage(): string
    {
        return $this->encrypted;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }
}
