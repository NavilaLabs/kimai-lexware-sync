<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Controller;

use App\Controller\AbstractController;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\WebhookSubscriptionOutcome;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\WebhookSubscriptionConnector;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/admin/lexware-sync/webhooks')]
#[IsGranted('manage_lexware_sync')]
final class WebhookSubscriptionController extends AbstractController
{
    public function __construct(
        private readonly WebhookSubscriptionConnector $connector,
        private readonly LexwareSyncConfiguration $configuration,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/connect', name: 'lexware_sync_connect_webhooks', methods: ['POST'])]
    public function connect(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('lexware_sync_connect_webhooks', $request->headers->get('X-CSRF-TOKEN'))) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('lexware_sync.connect_webhooks_invalid_token', [], 'messages'),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $baseUrl = $this->configuration->getPublicBaseUrl();

        $orderConfirmationCallbackUrl = $baseUrl !== ''
            ? $baseUrl . '/webhook/lexware/order-confirmation'
            : $this->generateUrl('lexware_sync_webhook_order_confirmation', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $invoiceCallbackUrl = $baseUrl !== ''
            ? $baseUrl . '/webhook/lexware/invoice'
            : $this->generateUrl('lexware_sync_webhook_invoice', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $results = $this->connector->connect($orderConfirmationCallbackUrl, $invoiceCallbackUrl);

        $created = 0;
        $existing = 0;
        $failures = [];

        foreach ($results as $result) {
            match ($result->outcome) {
                WebhookSubscriptionOutcome::Created => $created++,
                WebhookSubscriptionOutcome::AlreadyConnected => $existing++,
                WebhookSubscriptionOutcome::Failed => $failures[] = \sprintf('%s: %s', $result->eventType, $result->errorMessage),
            };
        }

        $message = $this->translator->trans('lexware_sync.connect_webhooks_result', [
            '%created%' => $created,
            '%existing%' => $existing,
            '%failed%' => \count($failures),
        ], 'messages');

        if ($failures !== []) {
            $message .= ' ' . implode(' ', $failures);
        }

        return new JsonResponse(['success' => $failures === [], 'message' => $message]);
    }
}
