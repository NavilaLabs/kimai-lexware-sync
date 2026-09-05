<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Controller;

use App\Controller\AbstractController;
use App\Utils\PageSetup;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\DocumentStatusFilter;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\CustomerCurrencyMismatchException;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareDocumentSummaryFactory;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationProcessor;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\UnprocessableOrderConfirmationException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/lexware-sync/triage')]
#[IsGranted('manage_lexware_sync')]
final class TriageController extends AbstractController
{
    public function __construct(
        private readonly TrackedOrderConfirmationRepository $repository,
        private readonly OrderConfirmationProcessor $processor,
        private readonly LexwareSyncConfiguration $configuration,
        private readonly LexwareDocumentSummaryFactory $summaryFactory,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '', name: 'lexware_sync_triage', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = DocumentStatusFilter::fromRequestValue($request->query->get('status'));
        $orderConfirmations = $this->repository->findByStatusFilter($filter);

        $summaries = [];
        foreach ($orderConfirmations as $orderConfirmation) {
            $summaries[(int) $orderConfirmation->getId()] = $this->summaryFactory->fromRawPayload($orderConfirmation->getRawPayload());
        }

        $page = new PageSetup('lexware_sync.triage.title');
        $page->setTranslationDomain('messages');

        return $this->render('@KimaiLexwareSync/triage/index.html.twig', [
            'page_setup' => $page,
            'orderConfirmations' => $orderConfirmations,
            'summaries' => $summaries,
            'filter' => $filter->value,
            'counts' => $this->countByStatusFilter(),
        ]);
    }

    #[Route(path: '/{id}/convert', name: 'lexware_sync_triage_convert', methods: ['POST'])]
    public function convert(int $id, Request $request): RedirectResponse
    {
        $filter = DocumentStatusFilter::fromRequestValue($request->request->get('status'));

        $orderConfirmation = $this->repository->find($id);
        if ($orderConfirmation === null || !$orderConfirmation->getStatus()->isConvertible()) {
            return $this->redirectToTriage($filter);
        }

        if (!$this->isCsrfTokenValid('lexware_sync_triage', $request->request->get('_token'))) {
            return $this->redirectToTriage($filter);
        }

        $payload = json_decode($orderConfirmation->getRawPayload(), true);

        $this->entityManager->beginTransaction();

        try {
            $this->processor->convert(
                $orderConfirmation,
                \is_array($payload) ? $payload : [],
                $this->getUser(),
                $this->configuration->getLineRegex(),
                $this->configuration->isReadLinesEnabled(),
            );
            $this->repository->save($orderConfirmation);

            $this->entityManager->commit();
        } catch (CustomerCurrencyMismatchException | UnprocessableOrderConfirmationException $exception) {
            $this->entityManager->rollback();

            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        return $this->redirectToTriage($filter);
    }

    #[Route(path: '/{id}/reject', name: 'lexware_sync_triage_reject', methods: ['POST'])]
    public function reject(int $id, Request $request): RedirectResponse
    {
        $filter = DocumentStatusFilter::fromRequestValue($request->request->get('status'));

        $orderConfirmation = $this->repository->find($id);
        if ($orderConfirmation === null || !$orderConfirmation->getStatus()->isPending()) {
            return $this->redirectToTriage($filter);
        }

        if (!$this->isCsrfTokenValid('lexware_sync_triage', $request->request->get('_token'))) {
            return $this->redirectToTriage($filter);
        }

        $orderConfirmation->markRejected($this->getUser());
        $this->repository->save($orderConfirmation);

        return $this->redirectToTriage($filter);
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

    private function redirectToTriage(DocumentStatusFilter $filter): RedirectResponse
    {
        return $this->redirectToRoute('lexware_sync_triage', ['status' => $filter->value]);
    }
}
