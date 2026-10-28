<?php

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Validates that APP_TOKEN_TTL is expressed in seconds and is not absurdly short.
 *
 * APP_TOKEN_TTL is consumed by TokenLinkService as a number of SECONDS. Writing a
 * small number there (e.g. "7" meaning a week) silently produces links that expire
 * in seconds, which is indistinguishable from a broken app for the end user: the
 * link page still renders, and the recipient hits "This link has expired" almost
 * immediately.
 *
 * This pass fails fast at container compile time when the value cannot be read as
 * an integer or is below MIN_TOKEN_TTL_SECONDS, and always points at the unit.
 *
 * The raw environment value is read directly rather than via a container parameter
 * because container parameters are resolved lazily: during compilation
 * "%env(int:APP_TOKEN_TTL)" is still the unresolved placeholder string, so a
 * parameter-based check would be meaningless here.
 */
class TokenTtlValidationPass implements CompilerPassInterface
{
    /**
     * Smallest TTL that is plausibly intentional: 5 minutes.
     *
     * Deliberately far below any real production value (1 day = 86400,
     * 7 days = 604800) and far above the sub-minute values that indicate a
     * unit mistake.
     */
    public const MIN_TOKEN_TTL_SECONDS = 300;

    public function process(ContainerBuilder $container): void
    {
        $raw = $this->readRawEnv('APP_TOKEN_TTL');

        // Leave the default (604800) untouched when the variable is not set.
        if ($raw === null || $raw === '') {
            return;
        }

        if (!is_numeric($raw) || (string) (int) $raw !== trim($raw)) {
            throw new \RuntimeException(
                'APP_TOKEN_TTL must be an integer number of SECONDS, got "' . $raw . '". '
                . 'Use 604800 for 7 days or 86400 for 1 day.'
            );
        }

        $ttl = (int) $raw;

        if ($ttl < self::MIN_TOKEN_TTL_SECONDS) {
            throw new \RuntimeException(
                'APP_TOKEN_TTL is ' . $ttl . ' seconds, which is far too short for shareable links. '
                . 'The value is interpreted in SECONDS. Use 604800 for 7 days or 86400 for 1 day.'
            );
        }
    }

    /**
     * Reads a raw environment variable from wherever the runtime put it.
     *
     * Dotenv::bootEnv() populates both $_ENV and $_SERVER, but a deployment may
     * also export variables through the process environment or php-fpm, so all
     * three sources are checked.
     */
    private function readRawEnv(string $name): ?string
    {
        if (isset($_ENV[$name]) && is_scalar($_ENV[$name])) {
            return (string) $_ENV[$name];
        }

        if (isset($_SERVER[$name]) && is_scalar($_SERVER[$name])) {
            return (string) $_SERVER[$name];
        }

        $fromGetenv = getenv($name);

        if ($fromGetenv === false) {
            return null;
        }

        return (string) $fromGetenv;
    }
}
