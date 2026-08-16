<?php

namespace App\Exception;

use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class ErrorHandler
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function handleServiceException(Exception $e, string $customMessage): never
    {
        $this->logger->error($customMessage, ['error' => $e->getMessage()]);
        throw new AppException($customMessage, 0, $e);
    }

    public function handleControllerException(Exception $e, string $customMessage): Response
    {
        $this->logger->error($customMessage, ['error' => $e->getMessage()]);

        $statusCode = $e instanceof AppException
            ? Response::HTTP_BAD_REQUEST
            : Response::HTTP_INTERNAL_SERVER_ERROR;

        $safeMessage = htmlspecialchars($customMessage, ENT_QUOTES, 'UTF-8');

        return new Response(
            sprintf(
                '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error</title>
                 <link rel="stylesheet" href="/bootstrap/bootstrap.min.css">
                 </head><body class="bg-dark text-light">
                 <div class="container py-5">
                     <div class="row justify-content-center">
                         <div class="col-md-6">
                             <div class="card bg-body-tertiary border border-danger-subtle">
                                 <div class="card-body text-center">
                                     <h1 class="h4 text-danger mb-3">Error</h1>
                                     <p class="mb-4">%s</p>
                                     <p><a href="/" class="btn btn-primary">Return to homepage</a></p>
                                 </div>
                             </div>
                         </div>
                     </div>
                 </div>
                 </body></html>',
                $safeMessage
            ),
            $statusCode
        );
    }
}
