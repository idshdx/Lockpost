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
        throw new AppException($customMessage . ': ' . $e->getMessage());
    }

    public function handleControllerException(Exception $e, string $customMessage): Response
    {
        $this->logger->error($customMessage, ['error' => $e->getMessage()]);
        return new Response(
            sprintf(
                '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error</title></head><body><h1>%s</h1><p>%s</p><p><a href="/">Return to homepage</a></p></body></html>',
                htmlspecialchars($customMessage),
                htmlspecialchars($e->getMessage())
            ),
            Response::HTTP_BAD_REQUEST
        );
    }
}
