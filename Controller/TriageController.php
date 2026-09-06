<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Controller;

use App\Controller\AbstractController;
use App\Utils\PageSetup;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\DocumentStatusFilter;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\Query\DocumentListQuery;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\CustomerCurrencyMismatchException;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareDocumentSummaryFactory;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseRequiredException;
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
        $listQuery = DocumentListQuery::fromParameters($request->query);
        $orderConfirmations = $this->repository->findPage($listQuery);

        $summaries = [];

        /** @var TrackedOrderConfirmation $orderConfirmation */
        foreach ($orderConfirmations as $orderConfirmation) {
            $summaries[(int) $orderConfirmation->getId()] = $this->summaryFactory->fromRawPayload($orderConfirmation->getRawPayload());
        }

        $pageSetup = new PageSetup('lexware_sync.triage.title');
        $pageSetup->setTranslationDomain('messages');

        return $this->render('@KimaiLexwareSync/triage/index.html.twig', [
            'page_setup' => $pageSetup,
            'orderConfirmations' => $orderConfirmations,
            'summaries' => $summaries,
            'filter' => $listQuery->status->value,
            'searchTerm' => $listQuery->searchTerm,
            'listRouteParameters' => $listQuery->toRouteParameters(),
            'counts' => $this->countByStatus($listQuery),
        ]);
    }

    #[Route(path: '/{id}/convert', name: 'lexware_sync_triage_convert', methods: ['POST'])]
    public function convert(int $id, Request $request): RedirectResponse
    {
        $listQuery = DocumentListQuery::fromParameters($request->request);

        $orderConfirmation = $this->repository->find($id);
        if ($orderConfirmation === null || !$orderConfirmation->getStatus()->isConvertible()) {
            return $this->redirectToTriage($listQuery);
        }

        if (!$this->isCsrfTokenValid('lexware_sync_triage', $request->request->getString('_token'))) {
            return $this->redirectToTriage($listQuery);
        }

        $this->entityManager->beginTransaction();

        try {
            $this->processor->convert(
                $orderConfirmation,
                LexwarePayload::fromJson($orderConfirmation->getRawPayload()),
                $this->getUser(),
                $this->configuration->getLineRegex(),
                $this->configuration->isReadLinesEnabled(),
            );
            $this->repository->save($orderConfirmation);

            $this->entityManager->commit();
        } catch (CustomerCurrencyMismatchException | LicenseRequiredException | UnprocessableOrderConfirmationException $exception) {
            $this->entityManager->rollback();

            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        return $this->redirectToTriage($listQuery);
    }

    #[Route(path: '/{id}/reject', name: 'lexware_sync_triage_reject', methods: ['POST'])]
    public function reject(int $id, Request $request): RedirectResponse
    {
        $listQuery = DocumentListQuery::fromParameters($request->request);

        $orderConfirmation = $this->repository->find($id);
        if ($orderConfirmation === null || !$orderConfirmation->getStatus()->isPending()) {
            return $this->redirectToTriage($listQuery);
        }

        if (!$this->isCsrfTokenValid('lexware_sync_triage', $request->request->getString('_token'))) {
            return $this->redirectToTriage($listQuery);
        }

        $orderConfirmation->markRejected($this->getUser());
        $this->repository->save($orderConfirmation);

        return $this->redirectToTriage($listQuery);
    }

    /**
     * @return array<string, int>
     */
    private function countByStatus(DocumentListQuery $listQuery): array
    {
        $counts = [];

        foreach (DocumentStatusFilter::cases() as $case) {
            $counts[$case->value] = $this->repository->countByListQuery($listQuery->withStatus($case));
        }

        return $counts;
    }

    private function redirectToTriage(DocumentListQuery $listQuery): RedirectResponse
    {
        return $this->redirectToRoute(
            'lexware_sync_triage',
            $listQuery->toRouteParameters() + ['page' => $listQuery->page],
        );
    }
}
