<?php

namespace App\Controller;

use App\Form\EmailFormType;
use App\Service\LinkService;
use App\Service\PgpKeyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use InvalidArgumentException;
use Exception;

class DefaultController extends AbstractController
{
    public function __construct(
        private readonly LinkService $linkService,
        private readonly PgpKeyService $pgpKeyService,
        private readonly TranslatorInterface $translator,
        private readonly MailerInterface $mailer
    ) {}

    #[Route('/', name: 'app_index')]
    public function index(Request $request): Response
    {
        $form = $this->createForm(EmailFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $email = $form->get('email')->getData();
                
                if (!$this->pgpKeyService->verifyPublicKey($email)) {
                    $this->addFlash('error', $this->translator->trans('No valid PGP public key found for this email address'));
                    return $this->render('default/index.html.twig', [
                        'form' => $form->createView()
                    ]);
                }
                
                $token = $this->linkService->generateLink($email);
                
                return $this->render('default/link.html.twig', [
                    'token' => $token
                ]);
            } catch (\Exception $e) {
                $this->addFlash('error', $this->translator->trans('Could not retrieve PGP public key'));
                return $this->render('default/index.html.twig', [
                    'form' => $form->createView()
                ]);
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
            $publicKey = $this->pgpKeyService->getPublicKey($email);

            return $this->render('default/submit.html.twig', [
                'email' => $email,
                'publicKey' => $publicKey
            ]);
        } catch (\Exception $e) {
            throw $this->createNotFoundException('Invalid or expired link');
        }
    }
    #[Route('/api/submit', name: 'app_api_submit', methods: ['POST'])]    
    public function apiSubmit(Request $request): Response
    {
        try {
            $data = json_decode($request->getContent(), true);
            if (!isset($data['encrypted']) || !isset($data['recipient'])) {
                throw new InvalidArgumentException('Missing required fields');
            }

            $email = (new Email())
                ->from($this->getParameter('app.mail_from'))
                ->to($data['recipient'])
                ->subject('New Encrypted Message')
                ->text($data['encrypted']);

            $this->mailer->send($email);

            return $this->json(['status' => 'success']);

        } catch (Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}