<?php

namespace App\Tests\DependencyInjection;

use App\DependencyInjection\TokenTtlValidationPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class TokenTtlValidationPassTest extends TestCase
{
    private TokenTtlValidationPass $pass;
    private ContainerBuilder $container;

    /** @var array<string, mixed> */
    private array $savedEnv = [];

    /** @var array<string, mixed> */
    private array $savedServer = [];

    /** @var array<string, false|string> */
    private array $savedGetenv = [];

    protected function setUp(): void
    {
        $this->pass = new TokenTtlValidationPass();
        $this->container = new ContainerBuilder();

        // The pass reads the raw environment, not container parameters, so the
        // test has to control the real superglobals around every invocation.
        foreach (['APP_TOKEN_TTL'] as $name) {
            $this->savedEnv[$name] = $_ENV[$name] ?? null;
            $this->savedServer[$name] = $_SERVER[$name] ?? null;
            $this->savedGetenv[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }

        foreach ($this->savedServer as $name => $value) {
            if ($value === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $value;
            }
        }

        foreach ($this->savedGetenv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
    }

    /**
     * Clears every source the pass consults so each test starts unambiguous.
     */
    private function clearTtl(): void
    {
        unset($_ENV['APP_TOKEN_TTL'], $_SERVER['APP_TOKEN_TTL']);
        putenv('APP_TOKEN_TTL');
    }

    public function testShortTtlThrows(): void
    {
        $this->clearTtl();
        $_ENV['APP_TOKEN_TTL'] = '7';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('which is far too short');

        $this->pass->process($this->container);
    }

    public function testShortTtlThrowsEvenWhenOnlySetInServer(): void
    {
        $this->clearTtl();
        $_SERVER['APP_TOKEN_TTL'] = '60';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('which is far too short');

        $this->pass->process($this->container);
    }

    public function testNonIntegerTtlThrows(): void
    {
        $this->clearTtl();
        $_ENV['APP_TOKEN_TTL'] = 'seven-days';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be an integer number of SECONDS');

        $this->pass->process($this->container);
    }

    public function testErrorMessageMentionsSecondsAndGivesExamples(): void
    {
        $this->clearTtl();
        $_ENV['APP_TOKEN_TTL'] = '7';

        try {
            $this->pass->process($this->container);
            self::fail('Expected a RuntimeException for a 7 second TTL');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('SECONDS', $e->getMessage());
            self::assertStringContainsString('604800', $e->getMessage());
            self::assertStringContainsString('86400', $e->getMessage());
        }
    }

    public function testSevenDaysIsAccepted(): void
    {
        $this->clearTtl();
        $_ENV['APP_TOKEN_TTL'] = '604800';

        $this->pass->process($this->container);

        self::addToAssertionCount(1);
    }

    public function testOneDayIsAccepted(): void
    {
        $this->clearTtl();
        $_ENV['APP_TOKEN_TTL'] = '86400';

        $this->pass->process($this->container);

        self::addToAssertionCount(1);
    }

    public function testBoundaryValueAtMinimumIsAccepted(): void
    {
        $this->clearTtl();
        $_ENV['APP_TOKEN_TTL'] = (string) TokenTtlValidationPass::MIN_TOKEN_TTL_SECONDS;

        $this->pass->process($this->container);

        self::addToAssertionCount(1);
    }

    public function testValueJustBelowMinimumThrows(): void
    {
        $this->clearTtl();
        $_ENV['APP_TOKEN_TTL'] = (string) (TokenTtlValidationPass::MIN_TOKEN_TTL_SECONDS - 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('which is far too short');

        $this->pass->process($this->container);
    }

    public function testUnsetTtlIsLeftToTheServiceDefault(): void
    {
        $this->clearTtl();

        $this->pass->process($this->container);

        self::addToAssertionCount(1);
    }

    public function testEmptyTtlIsLeftToTheServiceDefault(): void
    {
        $this->clearTtl();
        $_ENV['APP_TOKEN_TTL'] = '';

        $this->pass->process($this->container);

        self::addToAssertionCount(1);
    }

    public function testGetenvFallbackIsHonoured(): void
    {
        $this->clearTtl();
        putenv('APP_TOKEN_TTL=120');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('which is far too short');

        $this->pass->process($this->container);
    }
}
