<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Controller;

use App\Controller\AbstractController;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiClient;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiException;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareDeepLink;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/lexware-sync/document')]
#[IsGranted('manage_lexware_sync')]
final class LexwareDocumentController extends AbstractController
{
    public function __construct(
        private readonly TrackedOrderConfirmationRepository $orderConfirmationRepository,
        private readonly TrackedInvoiceRepository $trackedInvoiceRepository,
        private readonly LexwareApiClient $client,
        private readonly LexwareDeepLink $deepLink,
    ) {
    }

    #[Route(path: '/order-confirmation/{id}/pdf', name: 'lexware_sync_document_order_confirmation_pdf', methods: ['GET'])]
    public function orderConfirmationPdf(int $id): Response
    {
        $orderConfirmation = $this->orderConfirmationRepository->find($id);
        if ($orderConfirmation === null) {
            return $this->redirectToRoute('lexware_sync_triage');
        }

        try {
            $document = $this->client->getOrderConfirmation($orderConfirmation->getLexwareId());
        } catch (LexwareApiException $exception) {
            $this->addFlash('error', 'lexware_sync.document.unavailable');

            return $this->redirectToRoute('lexware_sync_triage');
        }

        $fileId = $this->documentFileId($document);
        if ($fileId === null) {
            $this->addFlash('error', 'lexware_sync.document.no_pdf');

            return $this->redirectToRoute('lexware_sync_triage');
        }

        try {
            $content = $this->client->downloadDocumentFile($fileId);
        } catch (LexwareApiException $exception) {
            $this->addFlash('error', 'lexware_sync.document.unavailable');

            return $this->redirectToRoute('lexware_sync_triage');
        }

        return $this->buildPdfResponse($content, $orderConfirmation->getVoucherNumber());
    }

    #[Route(path: '/invoice/{id}/created', name: 'lexware_sync_document_created_invoice', methods: ['GET'])]
    public function createdInvoice(int $id): Response
    {
        $trackedInvoice = $this->trackedInvoiceRepository->find($id);
        $createdInvoiceLexwareId = $trackedInvoice?->getCreatedInvoiceLexwareId();
        if ($trackedInvoice === null || $createdInvoiceLexwareId === null) {
            return $this->redirectToRoute('lexware_sync_invoices');
        }

        try {
            $invoice = $this->client->getInvoice($createdInvoiceLexwareId);
        } catch (LexwareApiException $exception) {
            $this->addFlash('error', 'lexware_sync.document.unavailable');

            return $this->redirectToRoute('lexware_sync_invoices');
        }

        $voucherNumber = $invoice['voucherNumber'] ?? null;
        if (!\is_string($voucherNumber) || $voucherNumber === '') {
            $this->addFlash('error', 'lexware_sync.document.unavailable');

            return $this->redirectToRoute('lexware_sync_invoices');
        }

        return $this->redirect($this->deepLink->forInvoice($voucherNumber));
    }

    /**
     * @param array<string, mixed> $document
     */
    private function documentFileId(array $document): ?string
    {
        $files = $document['files'] ?? null;
        if (!\is_array($files)) {
            return null;
        }

        $fileId = $files['documentFileId'] ?? null;

        return \is_string($fileId) && $fileId !== '' ? $fileId : null;
    }

    private function buildPdfResponse(string $content, string $voucherNumber): Response
    {
        $fileName = ($voucherNumber !== '' ? $voucherNumber : 'lexware') . '.pdf';

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $fileName, 'lexware.pdf'),
        ]);
    }
}
