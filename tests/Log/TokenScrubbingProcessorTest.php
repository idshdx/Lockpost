<?php

namespace App\Tests\Log;

use App\Log\TokenScrubbingProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

class TokenScrubbingProcessorTest extends TestCase
{
    private TokenScrubbingProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new TokenScrubbingProcessor();
    }

    public function testScrubsTokenFromSubmitUrlInMessage(): void
    {
        $record = $this->createRecord('Error on /submit/ABCDEFGHIJ12345678901234567890');
        $processed = ($this->processor)($record);
        $this->assertStringContainsString('/submit/[REDACTED]', $processed->message);
        $this->assertStringNotContainsString('ABCDEFGHIJ12345678901234567890', $processed->message);
    }

    public function testScrubsTokenFromContext(): void
    {
        $record = $this->createRecord('Some error', ['token' => 'ABCDEFGHIJ12345678901234567890']);
        $processed = ($this->processor)($record);
        $this->assertSame('[REDACTED]', $processed->context['token']);
    }

    public function testScrubsEmailFromMessage(): void
    {
        $record = $this->createRecord('Failed to send to user@example.com');
        $processed = ($this->processor)($record);
        $this->assertStringContainsString('[EMAIL_REDACTED]', $processed->message);
        $this->assertStringNotContainsString('user@example.com', $processed->message);
    }

    public function testDoesNotScrubShortStringsInMessage(): void
    {
        $record = $this->createRecord('Short message here');
        $processed = ($this->processor)($record);
        $this->assertSame('Short message here', $processed->message);
    }

    public function testDoesNotScrubNonSensitiveContext(): void
    {
        $record = $this->createRecord('Error', ['status_code' => 500, 'path' => '/submit']);
        $processed = ($this->processor)($record);
        $this->assertSame(500, $processed->context['status_code']);
        $this->assertSame('/submit', $processed->context['path']);
    }

    public function testScrubsLongAlphanumericString(): void
    {
        $longToken = 'aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789_-extra';
        $record = $this->createRecord('Token value: ' . $longToken);
        $processed = ($this->processor)($record);
        $this->assertStringContainsString('[REDACTED]', $processed->message);
        $this->assertStringNotContainsString($longToken, $processed->message);
    }

    private function createRecord(string $message, array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: Level::Error,
            message: $message,
            context: $context,
        );
    }
}
