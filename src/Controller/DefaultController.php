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

    #[Route('/message/submit', name: 'app_submit_message', methods: ['POST'])]
    public function submitMessage(Request $request, ValidatorInterface $validator): Response
    {
        try {
            $data = json_decode($request->getContent(), true);
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
                'app_verify_url' => $this->generateUrl('app_verify'),
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
            }

            return $this->render('default/verify.html.twig', [
                'form' => $form->createView(),
                'server_public_key' => $serverPublicKey
            ]);
        } catch (Exception $e) {
            return $this->errorHandler->handleControllerException($e, 'Could not load verify page');
        }
    }

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
