<?php

namespace App\Controller;

use App\Service\PgpSigningService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles static pages: about, privacy, and server key download.
 */
class StaticPageController extends AbstractController
{
    public function __construct(
        private readonly PgpSigningService $pgpSigningService,
    ) {
    }

    #[Route('/about', name: 'app_about')]
    public function about(): Response
    {
        return $this->render('default/about.html.twig');
    }

    #[Route('/privacy', name: 'app_privacy')]
    public function privacy(): Response
    {
        return $this->render('default/privacy.html.twig');
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
