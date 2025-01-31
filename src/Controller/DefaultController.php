<?php

namespace App\Controller;

use App\Exception\AppException;
use App\Form\MessageSubmitRequest;
use App\Form\EmailFormType;
use App\Form\PgpVerifySignatureFormType;
use App\Service\TokenLinkService;
use App\Service\PgpKeyService;
use App\Service\PgpSigningService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Exception\ErrorHandler;

class DefaultController extends AbstractController
{
    private readonly LoggerInterface $logger;
    private ErrorHandler $errorHandler;

    /**
     * Constructor.
     *
     * @param ErrorHandler         $errorHandler     The error handler to throw exceptions
     * @param TokenLinkService     $linkService      The service to generate unique links
     * @param PgpKeyService        $pgpKeyService    The service to verify PGP keys
     * @param PgpSigningService    $pgpSigningService The service to sign and verify messages
     * @param TranslatorInterface $translator       The translator to translate messages
     * @param MailerInterface     $mailer           The mailer to send emails
     * @param LoggerInterface     $logger           The logger to log messages
     */
    public function __construct(
        ErrorHandler     $errorHandler,
        private readonly TokenLinkService $linkService,
        private readonly PgpKeyService $pgpKeyService,
        private readonly PgpSigningService $pgpSigningService,
        private readonly TranslatorInterface $translator,
        private readonly MailerInterface $mailer,
        LoggerInterface $logger
    ) {
        $this->errorHandler = $errorHandler;
        $this->logger = $logger;
    }

    /**
     * Handle the request to generate a secure message link.
     *
     * This method handles the form submission for generating a secure message link using an email address
     * associated with a PGP public key.
     * If the form is valid, and a PGP public key exists for the email,
     * a unique link is generated and rendered.
     * Otherwise, an error message is displayed.
     *
     * @param Request $request The HTTP request object.
     *
     * @return Response The HTTP response object, rendering either the index page with the form or
     *                  the link page with the generated link.
     */
    #[Route('/', name: 'app_index')]
    public function index(Request $request): Response
    {
        $form = $this->createForm(EmailFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $email = $form->get('email')->getData();

                if (!$this->pgpKeyService->verifyPublicKeyExists($email)) {
                    $this->addFlash('error', $this->translator->trans('No valid PGP public key found for this email address'));
                    return $this->render('default/index.html.twig', [
                        'form' => $form->createView()
                    ]);
                }

                $token = $this->linkService->generateLink($email);

                return $this->render('default/link.html.twig', [
                    'token' => $token
                ]);
            } catch (Exception $e) {
                return $this->errorHandler->handleControllerException($e, 'Could not retrieve PGP public key');
            }
        }

        return $this->render('default/index.html.twig', [
            'form' => $form->createView()
        ]);
    }

    /**
     * The action for the /submit/{token} route.
     *
     * @param string $token The token from the URL.
     *
     * @return Response The HTTP response object, rendering the submitted page with the email and public key.
     *
     * @throws Exception If the token is invalid or expired.
     */
    #[Route('/submit/{token}', name: 'app_submit')]
    public function submit(string $token): Response
    {
        try {
            $email = $this->linkService->validateLink($token);
            $publicKey = $this->pgpKeyService->getPublicKeyByEmail($email);

            return $this->render('default/submit.html.twig', [
                'email' => $email,
                'publicKey' => $publicKey
            ]);
        } catch (Exception $e) {
            return $this->errorHandler->handleControllerException($e, 'Invalid or expired link');
        }
    }

    /**
     * Handles the submission of an encrypted message.
     *
     * This method processes the incoming POST request to submit an encrypted message.
     * It validates the request data, signs the encrypted message, and sends it via email
     * to the intended recipient.
     * If validation errors occur, a JSON response with the
     * errors is returned.
     * Upon successful email delivery, a success status is returned.
     *
     * @param Request $request The HTTP request object containing the message data.
     * @param ValidatorInterface $validator The validator for validating the request data.
     *
     * @return Response A JSON response indicating success or failure of the submission.
     *
     * @throws Exception|TransportExceptionInterface If any processing or email sending fails.
     */
    #[Route('/message/submit', name: 'app_submit_message', methods: ['POST'])]
    public function submitMessage(Request $request, ValidatorInterface $validator): Response
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $dto = new MessageSubmitRequest($data);

            $errors = $validator->validate($dto);

            if (count($errors) > 0) {
                $errorMessages = [];

                foreach ($errors as $error) {
                    $errorMessages[] = $error->getMessage();
                }

                return $this->json(['errors' => $errorMessages], Response::HTTP_BAD_REQUEST);
            }

            $signature = $this->pgpSigningService->signMessage($dto->getEncryptedMessage());
            $serverPublicKey = $this->pgpSigningService->getServerPublicKey();

            $emailContent = $this->renderView('default/email_template.html.twig', [
                'app_verify_url' => $this->generateUrl('app_verify_signature'),
                'server_public_key' => trim($serverPublicKey),
                'encrypted_message' => trim($dto->getEncryptedMessage()),
                'signature' => trim($signature),
            ]);

            $email = (new Email())
                ->from($this->getParameter('app.mail_from'))
                ->to($dto->getRecipient())
                ->subject('New Encrypted Message')
                ->html($emailContent);

            $attempt = 0;
            $maxAttempts = 3;
            do {
                try {
                    $this->mailer->send($email);
                    break;
                } catch (Exception $e) {
                    $attempt++;
                    $this->errorHandler->handleControllerException($e, 'E-mail sending failed');
                }
            } while ($attempt < $maxAttempts);

            if ($attempt === $maxAttempts) {
                throw new AppException('Failed to send email after multiple attempts.');
            }

            return $this->json(['status' => 'success']);
        } catch (Exception $e) {
            return $this->errorHandler->handleControllerException($e, 'Failed to process API submission');
        }
    }

    /**
     * This controller handles the verification of a PGP signature.
     *
     * @param Request $request The HTTP request object.
     *
     * @return Response The HTTP response object, containing the verification form.
     *
     * @throws Exception If the public key could not be loaded.
     */
    #[Route('/verify', name: 'app_verify_signature')]
    public function verifySignaturePage(Request $request): Response
    {
        try {
            $serverPublicKey = $this->pgpSigningService->getServerPublicKey();

            $form = $this->createForm(PgpVerifySignatureFormType::class, null, [
                'default_public_key' => $serverPublicKey
            ]);

            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData();
                $this->handlePGPVerification($data, $request);
    /**
     * Handles the submission of the verification form, checks the validity of the submitted signature.
     *
     * @param Request $request The HTTP request object.
     *
     * @return Response The HTTP response object, containing the verification form with the result of the verification.
     *
     * @throws Exception If the public key could not be loaded.
     */
            }

            return $this->render('default/verify.html.twig', [
                'form' => $form->createView(),
                'server_public_key' => $serverPublicKey
            ]);
        } catch (Exception $e) {
            return $this->errorHandler->handleControllerException($e, 'Could not load verify page');
        }
    }

    /**
     * Handles the submission of the verification form, checks the validity of the submitted signature.
     *
     * This method handles the submission of the verification form, checks the validity of the submitted signature.
     * If the form is valid, it calls the `handlePGPVerification`
     * method to verify the signature and stores the data in the session.
     * If the form is invalid, it adds a flash message for each error.
     * If an exception occurs, it adds a flash message and logs the error.
     *
     * @param Request $request The HTTP request object.
     *
     * @return Response The HTTP response object, containing the verification form with the result of the verification.
     *
     * @throws Exception If the public key could not be loaded.
     */
    #[Route('/verify/signature', name: 'app_verify_signature_valid', methods: ['POST'])]
    public function verifyIsValidSignature(Request $request): Response
    {
        try  {
            $form = $this->createForm(PgpVerifySignatureFormType::class);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData();

                $session = $request->getSession();
                $session->set('last_message', $data['message']);
                $session->set('last_signature', $data['signature']);
                $session->set('last_public_key', $data['public_key']);

                $this->handlePGPVerification($data, $request);

            } else {
                foreach ($form->getErrors(true) as $error) {
                    $this->addFlash('danger', $error->getMessage());
                }
            }

            return $this->render('default/verify.html.twig', [
                'form' => $form->createView(),
                'server_public_key' => $this->pgpSigningService->getServerPublicKey(),
            ]);
        } catch (Exception $e) {
            // todo add a general error handling method
            $this->addFlash('error', $e->getMessage());
            $this->logger->error('error', ['message' => $e->getMessage()]);
            return new Response('An error occurred: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

    }

    /**
     * This controller action allows users to download the server's public key.
     *
     * @return Response The HTTP response object, containing the public key data.
     *
     * @throws Exception If the server's public key could not be loaded for any reason.
     */
    #[Route('/public-key', name: 'app_server_key_download')]
    public function downloadServerPublicKey(): Response
    {
        try {
            $config = $this->getParameter('gpg');
            if (!is_array($config) || !isset($config[$this->getParameter('kernel.environment')]['public_key_path'])) {
                throw new AppException('Invalid GPG configuration.');
            }
            $keyPath = $config[$this->getParameter('kernel.environment')]['public_key_path'];
            if (!file_exists($keyPath) || !is_readable($keyPath)) {
                throw new AppException('Public key file not accessible.');
            }
            $keyData = file_get_contents($keyPath);
            if ($keyData === false) {
                throw new AppException('Failed to read public key file.');
            }
            return new Response($keyData, Response::HTTP_OK, [
                'Content-Type' => 'text/plain',
                'Content-Disposition' => 'attachment; filename="server-public-key.asc"',
            ]);
        } catch (Exception $e) {
            return $this->errorHandler->handleControllerException($e, 'Error retrieving public key');
        }
    }

    /**
     * Handles the verification of the PGP signature, stores the result in the session and adds a flash message.
     *
     * @param array $data The data from the form, containing the message, signature and public key.
     * @param Request $request The HTTP request object.
     *
     * @return void
     *
     * @throws Exception If the message could not be verified.
     */
    private function handlePGPVerification(array $data, Request $request): void
    {
        try {
            $isValid = $this->pgpSigningService->verifySignature(
                $data['message'],
                $data['signature']
            );

            $session = $request->getSession();
            $session->set('last_message', $data['message']);
            $session->set('last_signature', $data['signature']);
            $session->set('last_public_key', $data['public_key']);

            $this->addFlash(
                $isValid ? 'success' : 'warning',
                $this->translator->trans($isValid ? 'Message signature is valid' : 'Message signature is invalid')
            );
        } catch (Exception $e) {
            $this->errorHandler->handleControllerException($e, 'Error verifying message');
        }
    }


}
