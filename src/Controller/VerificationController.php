<?php

namespace App\Controller;

use App\Service\PgpSigningService;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles PGP signature verification: the verification page and the
 * signature verification POST handler.
 */
class VerificationController extends AbstractController
{
    public function __construct(
        private readonly PgpSigningService $pgpSigningService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Renders the PGP signature verification page.
     *
     * This method creates and processes the PgpVerifySignatureForm.
     * It displays the form to the user for verifying the authenticity
     * of a PGP signed message.
     */
    #[Route('/verify', name: 'app_verify')]
    public function verifySignaturePage(): Response
    {
        $response = $this->render('default/verify.html.twig', [
            'serverPublicKey' => $this->pgpSigningService->getServerPublicKey(),
        ]);
        // Prevent caching of the verify page (contains server public key).
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        return $response;
    }

    /**
     * Verifies the authenticity of a PGP signed message.
     *
     * This method handles the submission of the PgpVerifySignatureForm and
     * uses the PgpSigningService to verify the authenticity of the message.
     * It renders the verification result as a flash message and stores the
     * result in the user's session.
     */
    #[Route('/verify/signature', name: 'app_verify_signature', methods: ['POST'])]
    public function verifyIsValidSignature(Request $request): Response
    {
        $form = $this->createForm(\App\Form\PgpVerifySignatureFormType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', 'Invalid form submission. Please check your input.');
            return $this->redirectToRoute('app_verify');
        }

        try {
            $data = $form->getData();
            $isValid = $this->pgpSigningService->verifySignature(
                $data['signed_message'],
                $data['public_key']
            );

            if ($isValid) {
                $this->addFlash('success', 'Signature verification successful! The message is authentic.');
            } else {
                $this->addFlash('danger', 'Signature verification failed! The message may have been tampered with.');
            }

            $request->getSession()->set('last_verification_result', $isValid);
        } catch (Exception $e) {
            $this->logger->error('Signature verification error', ['exception_class' => get_class($e)]);
            $this->addFlash('danger', 'Error during verification. Please check your inputs and try again.');
        }

        return $this->redirectToRoute('app_verify');
    }
}
