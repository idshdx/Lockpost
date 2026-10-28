<?php

namespace App\Tests\Controller;

use App\Controller\LinkController;
use App\Exception\AppException;
use App\Service\PgpKeyService;
use App\Service\TokenLinkService;
use App\Service\TokenStateService;
use App\DTO\PgpKeyResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Unit tests for LinkController's failed-key-lookup rate limiting.
 *
 * Covers the fix where the result of consume() was ignored, so the
 * link_generation_failed limiter (3 failures/hour/IP) counted failures but
 * never actually refused any — leaving the "no key" path open to unlimited
 * email probing.
 */
class LinkControllerTest extends TestCase
{
    private const IP = '127.0.0.1';

    private Request $request;
    private RateLimiterFactory $linkGenerationFailedFactory;

    protected function setUp(): void
    {
        $this->request = Request::create('/');
        $this->request->setSession(new Session(new MockArraySessionStorage()));

        $this->linkGenerationFailedFactory = new RateLimiterFactory(
            ['id' => 'link_generation_failed', 'policy' => 'fixed_window', 'limit' => 3, 'interval' => '1 hour'],
            new CacheStorage(new ArrayAdapter())
        );
    }

    private function limiterFactory(string $id, int $limit, string $interval): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => $interval],
            new CacheStorage(new ArrayAdapter())
        );
    }

    /**
     * Builds a controller wired to a key service that always reports "no key"
     * (the path that exercises the failed-lookup limiter), and binds a minimal
     * container so addFlash() can write to the request's session.
     */
    private function controller(?PgpKeyService $keyService = null, ?LoggerInterface $logger = null): LinkController
    {
        if ($keyService === null) {
            $keyService = $this->createStub(PgpKeyService::class);
            $keyService->method('getPgpKeyResult')
                ->willThrowException(new AppException('No public key found for the provided email address'));
        }

        $controller = new LinkController(
            $this->createStub(TokenLinkService::class),
            $this->createStub(TokenStateService::class),
            $keyService,
            $this->limiterFactory('link_generation', 5, '1 minute'),
            $this->linkGenerationFailedFactory,
            $logger ?? new NullLogger(),
        );

        $requestStack = new RequestStack();
        $requestStack->push($this->request);
        $container = new Container();
        $container->set('request_stack', $requestStack);
        $controller->setContainer($container);

        return $controller;
    }

    /**
     * Invokes the private generateLinkResponse() and returns the flash that
     * was written to the session (the observable outcome of each path).
     */
    private function generateLink(LinkController $controller): ?Response
    {
        $method = new ReflectionMethod($controller, 'generateLinkResponse');
        $method->setAccessible(true);

        return $method->invoke($controller, $this->request, 'absent@nowhere.test');
    }

    /**
     * Returns the first 'danger' flash written during the last invocation.
     */
    private function lastFlash(): string
    {
        $messages = $this->request->getSession()->getFlashBag()->peek('danger');

        return $messages[0] ?? '';
    }

    public function testFailedLookupWithinLimitReportsMissingKey(): void
    {
        $response = $this->generateLink($this->controller());

        $this->assertNull($response);
        $this->assertStringContainsString('No valid PGP public key found', $this->lastFlash());
    }

    public function testFailedLookupsAreRefusedOnceLimiterIsExhausted(): void
    {
        // Spend the full budget (3 failures/hour) up front, as three earlier
        // lookups from this IP would have.
        for ($i = 0; $i < 3; $i++) {
            $limit = $this->linkGenerationFailedFactory->create(self::IP)->consume();
            $this->assertTrue($limit->isAccepted(), "seed consume $i should be within the limit");
        }

        $response = $this->generateLink($this->controller());

        // The next failure must be refused — before any keyserver work would
        // be reported — instead of being silently counted.
        $this->assertNull($response);
        $this->assertStringContainsString('Too many failed key lookups', $this->lastFlash());
        $this->assertStringNotContainsString('No valid PGP public key found', $this->lastFlash());
    }

    public function testGeneratedLinkResponseSetsNoStoreCacheHeaders(): void
    {
        $keyService = $this->createStub(PgpKeyService::class);
        $keyService->method('getPgpKeyResult')
            ->willReturn(new PgpKeyResult(
                publicKey: '-----BEGIN PGP PUBLIC KEY BLOCK-----...',
                source: 'keys.openpgp.org',
                fingerprint: '1234ABCD',
                emails: ['present@example.com'],
            ));

        $tokenLinkService = $this->createStub(TokenLinkService::class);
        $tokenLinkService->method('generateLink')->willReturn('v2-mock-token-xyz');
        $tokenLinkService->method('getExpirationPeriod')->willReturn(604800);

        $twig = $this->createStub(\Twig\Environment::class);
        $twig->method('render')->willReturn('<html>link ready</html>');

        $controller = new LinkController(
            $tokenLinkService,
            $this->createStub(TokenStateService::class),
            $keyService,
            $this->limiterFactory('link_generation', 5, '1 minute'),
            $this->linkGenerationFailedFactory,
            new NullLogger(),
        );

        $requestStack = new RequestStack();
        $requestStack->push($this->request);
        $container = new Container();
        $container->set('request_stack', $requestStack);
        $container->set('twig', $twig);
        $controller->setContainer($container);

        $method = new ReflectionMethod($controller, 'generateLinkResponse');
        $method->setAccessible(true);
        /** @var Response $response */
        $response = $method->invoke($controller, $this->request, 'present@example.com');

        $this->assertInstanceOf(Response::class, $response);
        // Symfony normalizes Cache-Control into a canonical sorted directive set
        // (e.g. "max-age=0, must-revalidate, no-cache, no-store, private"), so
        // assert on the directives being present rather than an exact string.
        $cacheControl = $response->headers->get('Cache-Control');
        foreach (['no-store', 'no-cache', 'must-revalidate', 'max-age=0'] as $directive) {
            $this->assertStringContainsString($directive, $cacheControl, "Cache-Control must include '$directive'");
        }
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
    }

    public function testIndexLogsErrorAndFlashesMessageOnUnexpectedException(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Link generation failed: {message}',
                $this->callback(function (array $context): bool {
                    return ($context['message'] ?? '') === 'Unexpected disk or keyring failure'
                        && ($context['exception'] ?? '') === \RuntimeException::class;
                })
            );

        $keyService = $this->createStub(PgpKeyService::class);
        $keyService->method('getPgpKeyResult')
            ->willThrowException(new \RuntimeException('Unexpected disk or keyring failure'));

        $form = $this->createMock(\Symfony\Component\Form\FormInterface::class);
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);
        $emailField = $this->createMock(\Symfony\Component\Form\FormInterface::class);
        $emailField->method('getData')->willReturn('user@example.com');
        $form->method('get')->with('email')->willReturn($emailField);
        $form->method('createView')->willReturn($this->createStub(\Symfony\Component\Form\FormView::class));

        $formFactory = $this->createMock(\Symfony\Component\Form\FormFactoryInterface::class);
        $formFactory->method('create')->willReturn($form);

        $twig = $this->createStub(\Twig\Environment::class);
        $twig->method('render')->willReturn('<html>homepage form</html>');

        $controller = $this->controller($keyService, $logger);

        $requestStack = new RequestStack();
        $requestStack->push($this->request);
        $container = new Container();
        $container->set('request_stack', $requestStack);
        $container->set('form.factory', $formFactory);
        $container->set('twig', $twig);
        $controller->setContainer($container);

        $response = $controller->index($this->request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(
            'Something went wrong while generating your link. Please try again.',
            $this->lastFlash()
        );
    }
}
