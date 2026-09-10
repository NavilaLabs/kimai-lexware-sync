<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Controller;

use KimaiPlugin\KimaiLexwareSyncBundle\Dto\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\WebhookEvent;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\WebhookEventRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\InvoiceSynchronizer;
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
        private readonly OrderConfirmationSynchronizer $orderConfirmationSynchronizer,
        private readonly InvoiceSynchronizer $invoiceSynchronizer,
    ) {
    }

    #[Route(path: '/order-confirmation', name: 'lexware_sync_webhook_order_confirmation', methods: ['POST'])]
    public function orderConfirmation(Request $request): Response
    {
        return $this->handle($request, function (string $resourceId): void {
            $this->orderConfirmationSynchronizer->synchronize($resourceId);
        });
    }

    #[Route(path: '/invoice', name: 'lexware_sync_webhook_invoice', methods: ['POST'])]
    public function invoice(Request $request): Response
    {
        return $this->handle($request, function (string $resourceId): void {
            $this->invoiceSynchronizer->synchronize($resourceId);
        });
    }

    private function handle(Request $request, callable $synchronize): Response
    {
        $rawBody = $request->getContent();
        $headers = $request->headers->all();
        $signatureValid = $this->verifier->verify($rawBody, $headers);

        // Read only to know what to refetch. Nothing from here is ever processed as data.
        $decoded = LexwarePayload::fromJson($rawBody);
        $eventType = $decoded->string('eventType', 'unknown');
        $resourceId = $decoded->nullableString('resourceId');

        $webhookEvent = new WebhookEvent($eventType, $resourceId, $signatureValid);
        $this->webhookEventRepository->save($webhookEvent);

        if (!$signatureValid) {
            return new JsonResponse(['message' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
        }

        if ($resourceId === null || $resourceId === '') {
            $webhookEvent->markFailed('Webhook payload carried no resource identifier');
            $this->webhookEventRepository->save($webhookEvent);

            return new JsonResponse(['message' => 'No resource identifier'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $synchronize($resourceId);
            $webhookEvent->markProcessed();
        } catch (\Throwable $exception) {
            $webhookEvent->markFailed($exception->getMessage());
        }

        $this->webhookEventRepository->save($webhookEvent);

        return new JsonResponse(['message' => 'Accepted']);
    }
}
