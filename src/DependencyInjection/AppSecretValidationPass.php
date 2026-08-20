<?php

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Validates that APP_SECRET is sufficiently strong in production.
 *
 * A weak or default APP_SECRET compromises token security (AES-256-CBC + HMAC-SHA256).
 * This pass fails fast at container compile time in prod if the secret is too short
 * or matches the default placeholder.
 */
class AppSecretValidationPass implements CompilerPassInterface
{
    /**
     * Minimum byte length for APP_SECRET (32 bytes = 256 bits of entropy).
     */
    private const MIN_SECRET_LENGTH = 32;

    /**
     * Known insecure placeholder values that must never be used in production.
     */
    private const INSECURE_VALUES = [
        'change-me-to-a-random-32-char-string',
        'change-me',
        'this_secret_will_be_used_in_tests_and_as_fallback',
    ];

    public function process(ContainerBuilder $container): void
    {
        // Only enforce in production and staging environments.
        $env = $container->getParameter('kernel.environment');
        if (in_array($env, ['dev', 'test'], true)) {
            return;
        }

        if (!$container->hasParameter('kernel.secret')) {
            return;
        }

        /** @var string $secret */
        $secret = $container->getParameter('kernel.secret');

        $errors = $this->validateSecret($secret);
        if ($errors !== []) {
            $message = 'APP_SECRET is not secure for ' . $env . " environment:\n  - " . implode("\n  - ", $errors);
            $message .= "\n\nGenerate a strong secret with:\n  php -r \"echo bin2hex(random_bytes(32));\"";

            throw new \RuntimeException($message);
        }
    }

    /**
     * Validates the APP_SECRET value and returns a list of error messages.
     */
    private function validateSecret(string $secret): array
    {
        $errors = [];

        // Check minimum length (raw bytes, not just string length).
        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            $errors[] = sprintf(
                'APP_SECRET is only %d characters long (minimum %d required).',
                strlen($secret),
                self::MIN_SECRET_LENGTH
            );
        }

        // Check for known insecure defaults.
        foreach (self::INSECURE_VALUES as $insecure) {
            if ($secret === $insecure) {
                $errors[] = sprintf(
                    "APP_SECRET matches the insecure default value '%s'.",
                    $insecure
                );
            }
        }

        // Check for low entropy patterns (repeated characters, sequential, etc.).
        if (preg_match('/^(.)\1+$/', $secret)) {
            $errors[] = 'APP_SECRET consists of a single repeated character.';
        }

        if (strlen($secret) === 32 && ctype_xdigit($secret)) {
            $errors[] = 'APP_SECRET is 32 hex characters = only 16 bytes of entropy. Use at least 32 raw characters.';
        }

        return $errors;
    }
}
