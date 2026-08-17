<?php

namespace App\Controller;

use App\Exception\AppException;
use App\Form\MessageSubmitRequest;
use App\Service\TokenLinkService;
use App\Service\TokenStateService;
use App\Service\PgpKeyService;
use App\Service\PgpSigningService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Handles message submission: the encrypted message form and the
 * POST endpoint that encrypts and emails messages to recipients.
 */
class MessageController extends AbstractController
{
    public function __construct(
        private readonly TokenLinkService $linkService,
        private readonly TokenStateService $tokenStateService,
        private readonly PgpKeyService $pgpKeyService,
        private readonly PgpSigningService $pgpSigningService,
        private readonly MailerInterface $mailer,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.submit_ip')]
        private readonly RateLimiterFactory $submitIpLimiter,
        #[Autowire(service: 'limiter.submit_token')]
        private readonly RateLimiterFactory $submitTokenLimiter,
    ) {
    }

    /**
     * Handles the submission of a tokenized link to display the form for sending
     * encrypted messages.
     *
     * Validates the token to retrieve the associated email address and its PGP
     * public key.
     * Renders a form allowing the user to submit an encrypted message
     * using the recipient's public key.
     */
    #[Route('/submit/{token}', name: 'app_submit', requirements: ['token' => '[A-Za-z0-9_\\\\-]++'])]
    public function submit(string $token): Response
    {
        try {
            $email = $this->linkService->validateLink($token);

            // In stateful mode, also check token state (revocation, one-time-use, max submissions).
            if (!$this->tokenStateService->validateToken($token)) {
                throw new AppException('Token has been revoked, used, or is not tracked in stateful mode.');
            }

            $publicKey = $this->pgpKeyService->getPublicKeyByEmail($email);

            $response = $this->render('default/submit.html.twig', [
                'email' => $email,
                'token' => $token,
                'publicKey' => $publicKey
            ]);
            // Prevent caching of pages containing tokens.
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            return $response;
        } catch (Exception $e) {
            $this->logger->error('Token validation failed for submission link');
            $this->addFlash('danger', 'This link is invalid or has expired. Ask for a new one. Or use the form below to generate a link');
            return $this->redirectToRoute('app_home');
        }
    }

    /**
     * Handles the submission of a POST request containing the encrypted message,
     * and the recipient's email address.
     * Signs the message using the server's private key and sends the signed
     * message to the recipient via email.
     */
    #[Route('/message/submit', name: 'app_submit_message', methods: ['POST'])]
    public function submitMessage(Request $request, ValidatorInterface $validator): Response
    {
        try {
            $validationError = $this->validateAndResolveSubmission($request, $data, $validator);
            if ($validationError !== null) {
                return $validationError;
            }

            $this->sendEncryptedMessageEmail($data, $data->getRecipientEmail());

            // In stateful mode, record the token usage after successful send.
            $this->tokenStateService->consumeToken($data->getToken());

            return $this->json([
                'success' => true,
                'message' => 'Message sent successfully'
            ]);
        } catch (Exception $e) {
            return $this->handleSubmissionError($e);
        }
    }

    /**
     * Handles errors during message submission: logs the error and returns a JSON response.
     */
    private function handleSubmissionError(Exception $e): Response
    {
        $this->logger->error('Failed to submit message: ' . $e->getMessage());
        return $this->json([
            'success' => false,
            'error' => 'An internal error occurred while sending the message.'
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Parses the request body and validates CSRF, rate limits, and form data.
     * Returns an error Response if validation fails, null if valid.
     * On success, sets $dto to the validated MessageSubmitRequest.
     */
    private function validateAndResolveSubmission(Request $request, ?MessageSubmitRequest &$dto, ValidatorInterface $validator): ?Response
    {
        $data = $this->parseRequestBody($request);
        if ($data === null) {
            return $this->jsonError('Invalid JSON payload', Response::HTTP_BAD_REQUEST);
        }

        $error = $this->validateSubmission($request, $data, $validator, $dto);
        if ($error !== null) {
            return $error;
        }

        $recipientEmail = $this->resolveRecipient($dto->getToken());
        $dto->setRecipientEmail($recipientEmail ?? '');
        if ($recipientEmail === null) {
            return $this->jsonError('Invalid or expired submission token', Response::HTTP_BAD_REQUEST);
        }

        return null;
    }

    /**
     * Parses JSON body from the request. Returns null if the body is not valid JSON or not an array.
     */
    private function parseRequestBody(Request $request): ?array
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Exception) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Signs the message with the server's private key and sends the encrypted
     * message as an email to the recipient.
     */
    private function sendEncryptedMessageEmail(MessageSubmitRequest $dto, string $recipientEmail): void
    {
        $signedMessage = $this->pgpSigningService->signMessage($dto->getEncryptedMessage());
        $templateContext = [
            'message' => $dto->getEncryptedMessage(),
            'message_signature' => $signedMessage,
            'server_public_key' => $this->pgpSigningService->getServerPublicKey(),
            'app_verify_url' => $this->generateUrl('app_verify', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
        $email = (new Email())
            ->from($this->getParameter('app.mail_from'))
            ->to($recipientEmail)
            ->subject('New PGP Encrypted Message via Lockpost')
            ->html($this->renderView('email/message.html.twig', $templateContext))
            ->text($this->renderView('email/message.txt.twig', $templateContext));

        $this->mailer->send($email);
    }

    /**
     * Validates CSRF token, DTO, and rate limits for message submission.
     * Returns an error Response if validation fails, null if valid.
     * On success, sets $dto to the validated MessageSubmitRequest.
     */
    private function validateSubmission(Request $request, array $data, ValidatorInterface $validator, ?MessageSubmitRequest &$dto): ?Response
    {
        $csrfToken = new CsrfToken('submit_message', $data['_csrf_token'] ?? '');
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            return $this->jsonError('Invalid or missing CSRF token', Response::HTTP_BAD_REQUEST);
        }

        // Validate DTO before consuming rate limit buckets.
        $dto = new MessageSubmitRequest($data);
        $validationError = $this->validateDto($dto, $validator);
        if ($validationError !== null) {
            return $validationError;
        }

        // Only check rate limits after DTO validation passes.
        $rateLimitError = $this->checkRateLimits($request, $data);
        if ($rateLimitError !== null) {
            return $rateLimitError;
        }

        return null;
    }

    /**
     * Checks IP and token-based rate limits.
     * Returns an error Response if a limit is exceeded, null if OK.
     */
    private function checkRateLimits(Request $request, array $data): ?Response
    {
        $ipKey = $request->getClientIp() ?? 'unknown_ip';
        $ipLimiter = $this->submitIpLimiter->create($ipKey);
        $ipLimitResult = $ipLimiter->consume();
        if (!$ipLimitResult->isAccepted()) {
            $retryAfter = max(1, $ipLimitResult->getRetryAfter()->getTimestamp() - time());
            return $this->json([
                'success' => false,
                'error' => 'Too many requests. Please try again later.'
            ], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => 5,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        $tokenRaw = $data['token'] ?? $data['recipient'] ?? '';
        $tokenKey = hash('sha256', $tokenRaw);
        $tokenLimiter = $this->submitTokenLimiter->create($tokenKey);
        $tokenLimitResult = $tokenLimiter->consume();
        if (!$tokenLimitResult->isAccepted()) {
            $retryAfter = max(1, $tokenLimitResult->getRetryAfter()->getTimestamp() - time());
            return $this->json([
                'success' => false,
                'error' => 'Too many submissions for this link. Please try again later.'
            ], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => $retryAfter,
            ]);
        }

        return null;
    }

    /**
     * Validates the DTO and returns an error Response if validation fails.
     */
    private function validateDto(MessageSubmitRequest $dto, ValidatorInterface $validator): ?Response
    {
        $errors = $validator->validate($dto);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json([
                'success' => false,
                'errors' => $errorMessages
            ], Response::HTTP_BAD_REQUEST);
        }

        return null;
    }

    /**
     * Validates the token and returns the recipient email, or null on failure.
     */
    private function resolveRecipient(string $token): ?string
    {
        try {
            return $this->linkService->validateLink($token);
        } catch (AppException) {
            return null;
        }
    }

    /**
     * Helper to return a standard error JSON response.
     */
    private function jsonError(string $error, int $status): JsonResponse
    {
        return $this->json([
            'success' => false,
            'error' => $error
        ], $status);
    }
}
