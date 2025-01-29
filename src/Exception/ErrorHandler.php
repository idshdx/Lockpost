<?php

namespace App\Exception;

use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
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

    public function handleControllerException(Exception $e, string $customMessage): JsonResponse
    {
        $this->logger->error($customMessage, ['error' => $e->getMessage()]);
        return new JsonResponse([
            'error' => $e->getMessage(),
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
