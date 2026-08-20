<?php

namespace App\Controller;

use App\Exception\AppException;
use App\Form\EmailFormType;
use App\Service\LinkGenerationRateLimiter;
use App\Service\TokenLinkService;
use App\Service\TokenStateService;
use App\Service\PgpKeyService;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles link generation: the homepage form and the token link display page.
 */
class LinkController extends AbstractController
{
    public function __construct(
        private readonly TokenLinkService $linkService,
        private readonly TokenStateService $tokenStateService,
        private readonly PgpKeyService $pgpKeyService,
        #[Autowire(service: 'limiter.link_generation')]
        private readonly RateLimiterFactory $linkGenerationLimiter,
        #[Autowire(service: 'limiter.link_generation_failed')]
        private readonly RateLimiterFactory $linkGenerationFailedLimiter,
    ) {
    }

    /**
     * The homepage of the application.
     *
     * Displays a form to generate a link for sending a secure message.
     *
     * @param Request $request The HTTP request object.
     *
     * @return Response A rendered Twig template with the form data.
     */
    #[Route('/', name: 'app_home')]
    public function index(Request $request): Response
    {
        $form = $this->createForm(EmailFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $result = $this->generateLinkResponse($request, $form->get('email')->getData());
                if ($result !== null) {
                    return $result;
                }
            } catch (Exception $e) {
                return $this->redirectToRoute('app_home');
            }
        }

        return $this->render('default/index.html.twig', [
            'form' => $form->createView()
        ]);
    }

    /**
     * Generates a token link if the PGP key exists; otherwise adds a flash warning.
     * Returns a Response if a link was generated, null otherwise.
     *
     * Applies IP-based rate limiting before performing a keyserver lookup
     * to prevent abuse (unlimited outbound network requests).
     */
    private function generateLinkResponse(Request $request, string $email): ?Response
    {
        $ipKey = $request->getClientIp() ?? 'unknown_ip';
        $ipLimiter = $this->linkGenerationLimiter->create($ipKey);
        $ipLimitResult = $ipLimiter->consume();
        if (!$ipLimitResult->isAccepted()) {
            $retryAfter = max(1, $ipLimitResult->getRetryAfter()->getTimestamp() - time());
            $this->addFlash('danger', "Too many link generation requests. Please try again in {$retryAfter} seconds.");
            return null;
        }

        try {
            $keyResult = $this->pgpKeyService->getPgpKeyResult($email);
        } catch (AppException) {
            $failedLimiter = $this->linkGenerationFailedLimiter->create($ipKey);
            $failedLimiter->consume();

            $servers = implode("\n", array_map(
                fn(string $host) => "https://$host",
                PgpKeyService::getKeyServerNames()
            ));
            $this->addFlash('danger', "No valid PGP public key found for this email address.\nKeys were searched on:\n$servers");
            return null;
        }

        $token = $this->linkService->generateLink($email);

        // In stateful mode, register the token for revocation / one-time-use tracking.
        $expiration = time() + $this->linkService->getExpirationPeriod();
        $this->tokenStateService->registerToken($token, $expiration);

        return $this->render('default/link.html.twig', [
            'token' => $token,
            'keyFingerprint' => $keyResult->fingerprint,
            'keySource' => $keyResult->source,
            'keyEmails' => $keyResult->emails,
            'expirationDays' => (int) ceil($this->linkService->getExpirationPeriod() / 86400),
        ]);
    }
}
