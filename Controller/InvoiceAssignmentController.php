<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Repository\Query\TimesheetQuery;
use App\Repository\TimesheetRepository;
use App\Utils\PageSetup;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoice;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\DocumentStatusFilter;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\InvoiceLineShape;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceTimesheetRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\AmbiguousLexwareRequestException;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\CustomerCurrencyMismatchException;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\InvoiceProcessor;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiException;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareDeepLink;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareDocumentSummaryFactory;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\TimesheetRateResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/lexware-sync/invoices')]
#[IsGranted('manage_lexware_sync')]
final class InvoiceAssignmentController extends AbstractController
{
    public function __construct(
        private readonly TrackedInvoiceRepository $repository,
        private readonly TrackedInvoiceTimesheetRepository $trackedInvoiceTimesheetRepository,
        private readonly TimesheetRepository $timesheetRepository,
        private readonly InvoiceProcessor $processor,
        private readonly LexwareDocumentSummaryFactory $summaryFactory,
        private readonly TimesheetRateResolver $rateResolver,
        private readonly LexwareDeepLink $deepLink,
    ) {
    }

    #[Route(path: '', name: 'lexware_sync_invoices', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = DocumentStatusFilter::fromRequestValue($request->query->get('status'));
        $trackedInvoices = $this->repository->findByStatusFilter($filter);

        $summaries = [];
        $warnings = [];
        foreach ($trackedInvoices as $trackedInvoice) {
            $id = (int) $trackedInvoice->getId();
            $summaries[$id] = $this->summaryFactory->fromRawPayload($trackedInvoice->getRawPayload());
            $warnings[$id] = $trackedInvoice->getStatus()->isConverted()
                && $this->trackedInvoiceTimesheetRepository->hasModifiedTimesheets($trackedInvoice);
        }

        $convertedVoucherNumber = $request->query->get('converted_voucher_number');
        $convertedVoucherNumber = \is_string($convertedVoucherNumber) ? $convertedVoucherNumber : null;

        $page = new PageSetup('lexware_sync.invoice.title');
        $page->setTranslationDomain('messages');

        return $this->render('@KimaiLexwareSync/invoice/index.html.twig', [
            'page_setup' => $page,
            'trackedInvoices' => $trackedInvoices,
            'summaries' => $summaries,
            'warnings' => $warnings,
            'filter' => $filter->value,
            'counts' => $this->countByStatusFilter(),
            'convertedVoucherNumber' => $convertedVoucherNumber,
            'convertedVoucherUrl' => $convertedVoucherNumber !== null ? $this->deepLink->forInvoice($convertedVoucherNumber) : null,
        ]);
    }

    #[Route(path: '/{id}', name: 'lexware_sync_invoices_assign', methods: ['GET'])]
    public function assign(int $id): Response
    {
        $trackedInvoice = $this->repository->find($id);
        if ($trackedInvoice === null || !$trackedInvoice->getStatus()->isPending()) {
            return $this->redirectToRoute('lexware_sync_invoices');
        }

        return $this->renderAssignScreen($trackedInvoice, null, [], InvoiceLineShape::PerTimesheet);
    }

    #[Route(path: '/{id}/reject', name: 'lexware_sync_invoices_reject', methods: ['POST'])]
    public function reject(int $id, Request $request): RedirectResponse
    {
        $filter = DocumentStatusFilter::fromRequestValue($request->request->get('status'));

        $trackedInvoice = $this->repository->find($id);
        if ($trackedInvoice === null || !$trackedInvoice->getStatus()->isPending()) {
            return $this->redirectToIndex($filter);
        }

        if (!$this->isCsrfTokenValid('lexware_sync_invoices', $request->request->get('_token'))) {
            return $this->redirectToIndex($filter);
        }

        $trackedInvoice->markRejected($this->getUser());
        $this->repository->save($trackedInvoice);

        return $this->redirectToIndex($filter);
    }

    #[Route(path: '/{id}/reopen', name: 'lexware_sync_invoices_reopen', methods: ['POST'])]
    public function reopen(int $id, Request $request): RedirectResponse
    {
        $filter = DocumentStatusFilter::fromRequestValue($request->request->get('status'));

        $trackedInvoice = $this->repository->find($id);
        if ($trackedInvoice === null || !$trackedInvoice->getStatus()->isRejected()) {
            return $this->redirectToIndex($filter);
        }

        if (!$this->isCsrfTokenValid('lexware_sync_invoices', $request->request->get('_token'))) {
            return $this->redirectToIndex($filter);
        }

        $trackedInvoice->reopen();
        $this->repository->save($trackedInvoice);

        return $this->redirectToRoute('lexware_sync_invoices_assign', ['id' => $id]);
    }

    #[Route(path: '/{id}/convert', name: 'lexware_sync_invoices_convert', methods: ['POST'])]
    public function convert(int $id, Request $request): Response
    {
        $trackedInvoice = $this->repository->find($id);
        if ($trackedInvoice === null || !$trackedInvoice->getStatus()->isPending()) {
            return $this->redirectToRoute('lexware_sync_invoices');
        }

        if (!$this->isCsrfTokenValid('lexware_sync_invoices', $request->request->get('_token'))) {
            return $this->redirectToRoute('lexware_sync_invoices');
        }

        $project = $trackedInvoice->getRelatedOrderConfirmation()->getProject();
        $submittedIds = array_map('intval', (array) $request->request->all('timesheets'));
        $timesheets = $this->resolveSelectedTimesheets($request, $project);
        $shape = $this->resolveShape($request);

        if (\count($timesheets) < \count($submittedIds)) {
            $this->addFlash('warning', 'lexware_sync.invoice.timesheets_dropped');
        }

        try {
            $this->processor->convert(
                $trackedInvoice,
                $timesheets,
                $shape,
                $request->request->getBoolean('finalize'),
                $request->request->getBoolean('mark_project_completed'),
                $this->getUser(),
            );
        } catch (AmbiguousLexwareRequestException $exception) {
            $this->addFlash('error', 'lexware_sync.invoice.ambiguous_failure');

            return $this->redirectToRoute('lexware_sync_invoices_assign', ['id' => $id]);
        } catch (CustomerCurrencyMismatchException | LexwareApiException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('lexware_sync_invoices_assign', ['id' => $id]);
        }

        return $this->redirectToRoute('lexware_sync_invoices', ['converted_voucher_number' => $trackedInvoice->getVoucherNumber()]);
    }

    #[Route(path: '/{id}/check-status', name: 'lexware_sync_invoices_check_status', methods: ['POST'])]
    public function checkStatus(int $id, Request $request): Response
    {
        $trackedInvoice = $this->repository->find($id);
        if ($trackedInvoice === null || !$trackedInvoice->getStatus()->isPending()) {
            return $this->redirectToRoute('lexware_sync_invoices');
        }

        if (!$this->isCsrfTokenValid('lexware_sync_invoices', $request->request->get('_token'))) {
            return $this->redirectToRoute('lexware_sync_invoices');
        }

        $project = $trackedInvoice->getRelatedOrderConfirmation()->getProject();
        $submittedIds = array_map('intval', (array) $request->request->all('timesheets'));
        $timesheets = $this->resolveSelectedTimesheets($request, $project);
        $shape = $this->resolveShape($request);

        try {
            $plausibleMatch = $this->processor->findPlausibleMatch($trackedInvoice, $timesheets, $shape);
        } catch (LexwareApiException $exception) {
            $this->addFlash('error', 'lexware_sync.invoice.check_status_failed');
            $plausibleMatch = null;
        }

        return $this->renderAssignScreen($trackedInvoice, $plausibleMatch, $submittedIds, $shape);
    }

    #[Route(path: '/{id}/confirm-existing', name: 'lexware_sync_invoices_confirm_existing', methods: ['POST'])]
    public function confirmExisting(int $id, Request $request): RedirectResponse
    {
        $trackedInvoice = $this->repository->find($id);
        if ($trackedInvoice === null || !$trackedInvoice->getStatus()->isPending()) {
            return $this->redirectToRoute('lexware_sync_invoices');
        }

        if (!$this->isCsrfTokenValid('lexware_sync_invoices', $request->request->get('_token'))) {
            return $this->redirectToRoute('lexware_sync_invoices');
        }

        $existingLexwareInvoiceId = (string) $request->request->get('existing_lexware_invoice_id', '');
        if ($existingLexwareInvoiceId === '') {
            return $this->redirectToRoute('lexware_sync_invoices_assign', ['id' => $id]);
        }

        $project = $trackedInvoice->getRelatedOrderConfirmation()->getProject();
        $submittedIds = array_map('intval', (array) $request->request->all('timesheets'));
        $timesheets = $this->resolveSelectedTimesheets($request, $project);
        $shape = $this->resolveShape($request);

        if (\count($timesheets) < \count($submittedIds)) {
            $this->addFlash('warning', 'lexware_sync.invoice.timesheets_dropped');
        }

        try {
            $freshMatch = $this->processor->findPlausibleMatch($trackedInvoice, $timesheets, $shape);
        } catch (LexwareApiException $exception) {
            $this->addFlash('error', 'lexware_sync.invoice.check_status_failed');

            return $this->redirectToRoute('lexware_sync_invoices_assign', ['id' => $id]);
        }

        if ($freshMatch === null || (string) ($freshMatch['id'] ?? '') !== $existingLexwareInvoiceId) {
            $this->addFlash('error', 'lexware_sync.invoice.confirm_existing_mismatch');

            return $this->redirectToRoute('lexware_sync_invoices_assign', ['id' => $id]);
        }

        try {
            $this->processor->confirmExisting(
                $trackedInvoice,
                $existingLexwareInvoiceId,
                $timesheets,
                $request->request->getBoolean('mark_project_completed'),
                $this->getUser(),
            );
        } catch (CustomerCurrencyMismatchException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('lexware_sync_invoices_assign', ['id' => $id]);
        }

        return $this->redirectToRoute('lexware_sync_invoices', ['converted_voucher_number' => $trackedInvoice->getVoucherNumber()]);
    }

    /**
     * @param array<string, mixed>|null $plausibleMatch
     * @param int[] $selectedTimesheetIds
     */
    private function renderAssignScreen(
        TrackedInvoice $trackedInvoice,
        ?array $plausibleMatch,
        array $selectedTimesheetIds,
        InvoiceLineShape $shape
    ): Response {
        $summary = $this->summaryFactory->fromRawPayload($trackedInvoice->getRawPayload());
        $originalLines = $this->originalLines($trackedInvoice);

        $page = new PageSetup('lexware_sync.invoice.assign_title');
        $page->setTranslationDomain('messages');

        return $this->render('@KimaiLexwareSync/invoice/assign.html.twig', [
            'page_setup' => $page,
            'trackedInvoice' => $trackedInvoice,
            'summary' => $summary,
            'timesheetRows' => $this->buildTimesheetRows(
                $this->findEligibleTimesheets($trackedInvoice->getRelatedOrderConfirmation()->getProject()),
            ),
            'plausibleMatch' => $plausibleMatch,
            'originalLines' => $originalLines,
            'originalLinesTotal' => $this->originalLinesTotal($originalLines),
            'selectedTimesheetIds' => $selectedTimesheetIds,
            'selectedShape' => $shape->value,
            'draftUrl' => $this->deepLink->forInvoice($trackedInvoice->getVoucherNumber()),
        ]);
    }

    /**
     * @param Timesheet[] $timesheets
     * @return array<int, array<string, mixed>>
     */
    private function buildTimesheetRows(array $timesheets): array
    {
        $rows = [];

        foreach ($timesheets as $timesheet) {
            $rows[] = [
                'timesheet' => $timesheet,
                'hours' => $this->rateResolver->invoicedQuantityFor($timesheet),
                'hourlyRate' => $this->rateResolver->invoicedUnitPriceFor($timesheet),
                'amount' => $this->rateResolver->invoicedAmountFor($timesheet),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function originalLines(TrackedInvoice $trackedInvoice): array
    {
        $payload = json_decode($trackedInvoice->getRawPayload(), true);
        $lines = \is_array($payload) ? ($payload['lineItems'] ?? []) : [];

        return \is_array($lines) ? $lines : [];
    }

    /**
     * @param array<int, array<string, mixed>> $originalLines
     */
    private function originalLinesTotal(array $originalLines): float
    {
        $total = 0.0;

        foreach ($originalLines as $line) {
            $lineItemAmount = $line['lineItemAmount'] ?? 0;
            $total += \is_numeric($lineItemAmount) ? (float) $lineItemAmount : 0.0;
        }

        return round($total, 2);
    }

    /**
     * @return array<string, int>
     */
    private function countByStatusFilter(): array
    {
        $counts = [];

        foreach (DocumentStatusFilter::cases() as $case) {
            $counts[$case->value] = $this->repository->countByStatusFilter($case);
        }

        return $counts;
    }

    private function resolveShape(Request $request): InvoiceLineShape
    {
        return $request->request->get('shape') === InvoiceLineShape::AggregatedByActivity->value
            ? InvoiceLineShape::AggregatedByActivity
            : InvoiceLineShape::PerTimesheet;
    }

    /**
     * @return Timesheet[]
     */
    private function findEligibleTimesheets(?Project $project): array
    {
        if ($project === null) {
            return [];
        }

        $query = new TimesheetQuery();
        $query->setProjects([$project]);
        $query->setState(TimesheetQuery::STATE_STOPPED);
        $query->setExported(TimesheetQuery::STATE_NOT_EXPORTED);

        return $this->timesheetRepository->getTimesheetsForQuery($query);
    }

    /**
     * @return Timesheet[]
     */
    private function resolveSelectedTimesheets(Request $request, ?Project $project): array
    {
        $selectedIds = array_map('intval', (array) $request->request->all('timesheets'));
        if (\count($selectedIds) === 0) {
            return [];
        }

        $eligible = $this->findEligibleTimesheets($project);

        return array_values(array_filter(
            $eligible,
            static fn (Timesheet $timesheet) => \in_array($timesheet->getId(), $selectedIds, true),
        ));
    }

    private function redirectToIndex(DocumentStatusFilter $filter): RedirectResponse
    {
        return $this->redirectToRoute('lexware_sync_invoices', ['status' => $filter->value]);
    }
}
