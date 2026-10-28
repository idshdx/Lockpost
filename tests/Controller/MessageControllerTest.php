<?php

namespace App\Tests\Controller;

use App\Controller\MessageController;
use App\Exception\AppException;
use App\Service\PgpKeyService;
use App\Service\PgpSigningService;
use App\Service\TokenLinkService;
use App\Service\TokenStateService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Unit tests for MessageController's recipient resolution.
 *
 * Covers the security fix where a revoked or exhausted token could still send
 * mail by POSTing directly to /message/submit: the state check must run on the
 * POST path, not only when rendering the GET submission page.
 */
class MessageControllerTest extends TestCase
{
    private const TOKEN = 'tok-stateful';

    /**
     * Builds a controller whose resolveRecipient() can be exercised directly.
     *
     * RateLimiterFactory is final, so real instances (backed by an in-memory
     * cache) are constructed instead of mocks; resolveRecipient() does not
     * reach them anyway.
     */
    private function controller(TokenLinkService $linkService, TokenStateService $stateService): MessageController
    {
        $limiter = static fn(string $id): RateLimiterFactory => new RateLimiterFactory(
            ['id' => $id, 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '1 minute'],
            new CacheStorage(new ArrayAdapter())
        );

        return new MessageController(
            $linkService,
            $stateService,
            $this->createStub(PgpKeyService::class),
            $this->createStub(PgpSigningService::class),
            $this->createStub(MailerInterface::class),
            $this->createStub(CsrfTokenManagerInterface::class),
            $this->createStub(LoggerInterface::class),
            $limiter('submit_ip'),
            $limiter('submit_token'),
        );
    }

    public function testResolveRecipientReturnsEmailWhenTokenStateIsValid(): void
    {
        $linkService = $this->createMock(TokenLinkService::class);
        $linkService->expects($this->once())
            ->method('validateLink')
            ->with(self::TOKEN)
            ->willReturn('recipient@example.com');

        $stateService = $this->createMock(TokenStateService::class);
        $stateService->expects($this->once())
            ->method('validateToken')
            ->with(self::TOKEN)
            ->willReturn(true);

        $controller = $this->controller($linkService, $stateService);
        $method = new ReflectionMethod($controller, 'resolveRecipient');
        $method->setAccessible(true);

        $this->assertSame('recipient@example.com', $method->invoke($controller, self::TOKEN));
    }

    public function testResolveRecipientRejectsRevokedOrExhaustedToken(): void
    {
        // The link's signature is cryptographically valid, but the token state
        // says revoked/used-up. The POST path must reject it — previously the
        // state was only checked on the GET page, so posting directly to
        // /message/submit bypassed revocation and one-time-use entirely.
        $linkService = $this->createStub(TokenLinkService::class);
        $linkService->method('validateLink')->willReturn('recipient@example.com');

        $stateService = $this->createMock(TokenStateService::class);
        $stateService->expects($this->once())
            ->method('validateToken')
            ->with(self::TOKEN)
            ->willReturn(false);

        $controller = $this->controller($linkService, $stateService);
        $method = new ReflectionMethod($controller, 'resolveRecipient');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($controller, self::TOKEN));
    }

    public function testResolveRecipientReturnsNullWhenLinkIsInvalid(): void
    {
        $linkService = $this->createStub(TokenLinkService::class);
        $linkService->method('validateLink')->willThrowException(new AppException('bad token'));

        // State must not be consulted for a token that fails signature checks.
        $stateService = $this->createMock(TokenStateService::class);
        $stateService->expects($this->never())->method('validateToken');

        $controller = $this->controller($linkService, $stateService);
        $method = new ReflectionMethod($controller, 'resolveRecipient');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($controller, self::TOKEN));
    }
}
