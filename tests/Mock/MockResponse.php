<?php

namespace App\Tests\Mock;

use Symfony\Contracts\HttpClient\ResponseInterface;

class MockResponse implements ResponseInterface
{
    public function __construct(
        private int $statusCode,
        private string $body
    ) {}

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(bool $throw = true): array
    {
        return ['content-type' => ['text/plain']];
    }

    public function getContent(bool $throw = true): string
    {
        return $this->body;
    }

    public function toArray(bool $throw = true): array
    {
        return [];
    }

    public function cancel(): void {}

    public function getInfo(?string $type = null): mixed
    {
        return null;
    }
}
