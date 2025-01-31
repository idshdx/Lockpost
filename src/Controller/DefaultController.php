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
     * DefaultController constructor.
     *
     * @param ErrorHandler $errorHandler
     * @param TokenLinkService $linkService
     * @param PgpKeyService $pgpKeyService
     * @param PgpSigningService $pgpSigningService
     * @param MailerInterface $mailer
     * @param LoggerInterface $logger
     */
    public function __construct(
        ErrorHandler     $errorHandler,
        private readonly TokenLinkService $linkService,
        private readonly PgpKeyService $pgpKeyService,
        private readonly PgpSigningService $pgpSigningService,
        private readonly MailerInterface $mailer,
        LoggerInterface $logger
    ) {
        $this->errorHandler = $errorHandler;
        $this->logger = $logger;
    }

    /**
     * The homepage of the application.
     *
     * Displays a form to generate a link for sending a secure message.
     *
     * @param Request $request The HTTP request object.
     *
     * @return Response A rendered Twig template with the form data.
     *
     * @throws Exception If there is an error while generating the link,
     * such as if the recipient's email address has no associated PGP public key.
     */
    #[Route('/', name: 'app_home')]
    public function index(Request $request): Response
    {
        $form = $this->createForm(EmailFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $email = $form->get('email')->getData();

                if (!$this->pgpKeyService->verifyPublicKeyExists($email)) {
                    $this->addFlash('danger', 'No valid PGP public key found for this email address');
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
     * Handles the submission of a tokenized link to display the form for sending
     * encrypted messages.
     *
     * Validates the token to retrieve the associated email address and its PGP
     * public key.
     * Renders a form allowing the user to submit an encrypted message
     * using the recipient's public key.
     *
     * @param string $token The token used to validate and retrieve the recipient's
     *                      email and PGP public key.
     *
     * @return Response Renders the submission page for the encrypted message form
     *                  or an error message if the token is invalid or expired.
     */
    #[Route('/submit/{token}', name: 'app_submit', requirements: ['token' => '[A-Za-z0-9_\-]++'])]

    public function submit(string $token): Response
    {
        try {
            $email = $this->linkService->validateLink($token);
            $publicKey = $this->pgpKeyService->getPublicKeyByEmail($email);
    /**
     * @Route("/message/submit", name="app_submit_message", methods={"POST"})
     *
     * @param Request $request
     * @param ValidatorInterface $validator
     *
     * @return Response
     *
     * @throws Exception
     */
            return $this->render('default/submit.html.twig', [
                'email' => $email,
                'publicKey' => $publicKey
            ]);

        } catch (Exception $e) {
            return $this->errorHandler->handleControllerException($e, 'Invalid or expired link');
        }
    }

    /**
     * Handles the submission of a POST request containing the encrypted message,
     * and the recipient's email address.
     * Signs the message using the server's
     * private key and sends the signed message to the recipient via email.
     *
     * This endpoint is used by the client-side JavaScript to send encrypted
     * messages to the recipient.
     * The message is encrypted using the recipient's
     * public key and is signed using the server's private key.
     * The signed message
     * is then sent to the recipient via email.
     *
     * @param Request $request The request object containing the encrypted message
     *                         and the recipient's email address.
     * @param ValidatorInterface $validator The validator to use for validating
     *                                      the request data.
     *
     * @return Response A JSON response containing the result of the message
     *                 submission.
     * If the submission is successful, a success
     *                 message is returned with a status code of 200.
     * If the
     *                 submission fails due to validation errors, an error message
     *                 is returned with a status code of 400.
     * If the submission
     *                 fails due to an internal server error, an error message is
     *                 returned with a status code of 500.
     * @throws TransportExceptionInterface
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

            return $this->json([
                'success' => false,
                'errors' => $errorMessages
            ], Response::HTTP_BAD_REQUEST);
        }

        $signedMessage = $this->pgpSigningService->signMessage($dto->getEncryptedMessage());

        $email = (new Email())
            ->from($this->getParameter('app.mail_from'))
            ->to($dto->getRecipient())
            ->subject('New PGP Message')
            ->html($this->renderView('email/message.html.twig', [
                'message' => $dto->getEncryptedMessage(),
                'message_signature' => $signedMessage,
                'server_public_key' => $this->pgpSigningService->getServerPublicKey(),
                'app_verify_url' => $this->generateUrl('app_verify')
            ]));

        $this->mailer->send($email);

        return $this->json([
            'success' => true,
            'message' => 'Message sent successfully'
        ]);

    } catch (Exception $e) {
        return $this->json([
            'success' => false,
            'error' => $e->getMessage()
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}

    /**
     * Renders the PGP signature verification page.
     *
     * This method creates and processes the PgpVerifySignatureForm.
     * It displays the form to the user for verifying the authenticity
     * of a PGP signed message.
     * The form includes fields for the public
     * key, message, and signature.
     * Once the form is created, it is
     * passed to the Twig template for rendering.
     *
     * @param Request $request The HTTP request object.
     *
     * @return Response The rendered verification page with the form.
     */
    #[Route('/verify', name: 'app_verify')]
    public function verifySignaturePage(Request $request): Response
    {
        $form = $this->createForm(PgpVerifySignatureFormType::class);
        $form->handleRequest($request);

        return $this->render('default/verify.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Verifies the authenticity of a PGP signed message.
     *
     * This method handles the submission of the PgpVerifySignatureForm and
     * uses the PgpSigningService to verify the authenticity of the message.
     * It renders the verification result as a flash message and stores the
     * result in the user's session.
     *
     * @param Request $request The HTTP request object.
     *
     * @return Response A redirect to the verification page with the result.
     */
    #[Route('/verify/signature', name: 'app_verify_signature', methods: ['POST'])]
    public function verifyIsValidSignature(Request $request): Response
    {
        $form = $this->createForm(PgpVerifySignatureFormType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', 'Invalid form submission. Please check your input.');
            return $this->redirectToRoute('app_verify');

        }

        try {
            $data = $form->getData();
            $isValid = $this->pgpSigningService->verifySignature(
                $data['message'],
                $data['signature'],
                $data['public_key']
            );

            if ($isValid) {
                $this->addFlash('success', 'Signature verification successful! The message is authentic.');
            } else {
                $this->addFlash('danger', 'Signature verification failed! The message may have been tampered with.');
            }

            $request->getSession()->set('last_verification_result', $isValid);
        } catch (Exception $e) {
            $this->logger->error('Signature verification error: ' . $e->getMessage());
            $this->addFlash('danger', 'Error during verification: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_verify');
    }

    /**
     * Returns the server's public key as an HTTP response, allowing the user to download it.
     *
     * This endpoint is used to provide the server's public key to users who want to verify
     * the authenticity of the messages sent by the server.
     *
     * @return Response An HTTP response containing the server's public key.
     * @throws AppException
     */
    #[Route('/server-key', name: 'app_server_key_download')]
    public function downloadServerPublicKey(): Response
    {
        $publicKey = $this->pgpSigningService->getServerPublicKey();
        $response = new Response($publicKey);
        $response->headers->set('Content-Type', 'text/plain');
        $response->headers->set('Content-Disposition', 'attachment; filename="server-public-key.asc"');
        return $response;
    }
}
