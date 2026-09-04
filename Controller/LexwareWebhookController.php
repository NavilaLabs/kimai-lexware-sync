<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Controller;

use KimaiPlugin\KimaiLexwareSyncBundle\Entity\WebhookEvent;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\WebhookEventRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareWebhookVerifier;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationSynchronizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/webhook/lexware')]
final class LexwareWebhookController
{
    public function __construct(
        private readonly LexwareWebhookVerifier $verifier,
        private readonly WebhookEventRepository $webhookEventRepository,
        private readonly OrderConfirmationSynchronizer $synchronizer,
    ) {
    }

    #[Route(path: '/order-confirmation', name: 'lexware_sync_webhook_order_confirmation', methods: ['POST'])]
    public function orderConfirmation(Request $request): Response
    {
        $rawBody = $request->getContent();
        $headers = $request->headers->all();
        $signatureValid = $this->verifier->verify($rawBody, $headers);

        $decoded = json_decode($rawBody, true);
        $eventType = \is_array($decoded) ? (string) ($decoded['eventType'] ?? 'unknown') : 'unknown';
        $resourceId = \is_array($decoded) ? ($decoded['resourceId'] ?? null) : null;

        $webhookEvent = new WebhookEvent($eventType, \is_string($resourceId) ? $resourceId : null, $signatureValid);
        $this->webhookEventRepository->save($webhookEvent);

        if (!$signatureValid) {
            return new JsonResponse(['message' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
        }

        if (!\is_string($resourceId) || $resourceId === '') {
            $webhookEvent->markFailed('Webhook payload carried no resource identifier');
            $this->webhookEventRepository->save($webhookEvent);

            return new JsonResponse(['message' => 'No resource identifier'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->synchronizer->synchronize($resourceId);
            $webhookEvent->markProcessed();
        } catch (\Throwable $exception) {
            $webhookEvent->markFailed($exception->getMessage());
        }

        $this->webhookEventRepository->save($webhookEvent);

        return new JsonResponse(['message' => 'Accepted']);
    }
}
