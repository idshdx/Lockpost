<?php

namespace App\Log;

use Monolog\LogRecord;

/**
 * Scrubs PGP token strings from log messages and context.
 *
 * Submit tokens appear in URLs (/submit/{token}) and in error message
 * contexts. This processor provides defense-in-depth by matching and
 * redacting any token-like string before it reaches the log file.
 *
 * Token patterns matched:
 * - /submit/{token} URL paths
 * - Long base64url-style strings (potential tokens)
 * - Email addresses in log context
 */
class TokenScrubbingProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        // Scrub the log message line.
        $message = $this->scrub($record->message);

        // Scrub context values.
        $context = $record->context;
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = $this->scrub($value);
            }
        }

        // Monolog 3.x LogRecord is readonly → create a copy with scrubbed fields.
        return new LogRecord(
            datetime: $record->datetime,
            channel: $record->channel,
            level: $record->level,
            message: $message,
            context: $context,
        );
    }

    /**
     * Redacts token-like strings from a message.
     *
     * Matches:
     * - /submit/<token> URL paths (token matches [A-Za-z0-9_-]{16,})
     * - Standalone token strings (43-char base64url, common for PGP tokens)
     */
    private function scrub(string $message): string
    {
        // Redact /submit/{token} URL paths.
        $message = preg_replace(
            '#/submit/([A-Za-z0-9_\\-]{16,})#',
            '/submit/[REDACTED]',
            $message
        );

        // Redact any remaining long alphanumeric strings that look like tokens.
        // A token is at least 16 chars of base64url charset.
        $message = preg_replace(
            '#(?<![A-Za-z0-9_\\-])[A-Za-z0-9_\\-]{16,}(?![A-Za-z0-9_\\-])#',
            '[REDACTED]',
            $message
        );

        // Redact email addresses in log output.
        $message = preg_replace(
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}/',
            '[EMAIL_REDACTED]',
            $message
        );

        return $message;
    }
}
