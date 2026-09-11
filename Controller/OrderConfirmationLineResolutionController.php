<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Controller;

use App\Controller\AbstractController;
use App\Utils\PageSetup;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Dto\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Factory\LexwareDocumentSummaryFactory;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck\HealthCheckBanner;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseGate;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseVerdictMessageFormatter;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationLineDiffer;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationLineResolver;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\ProjectChangeIndicator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/lexware-sync/triage/{id}', requirements: ['id' => '\d+'])]
#[IsGranted('manage_lexware_sync')]
final class OrderConfirmationLineResolutionController extends AbstractController
{
    public function __construct(
        private readonly TrackedOrderConfirmationRepository $repository,
        private readonly OrderConfirmationLineDiffer $differ,
        private readonly OrderConfirmationLineResolver $resolver,
        private readonly LexwareSyncConfiguration $configuration,
        private readonly LexwareDocumentSummaryFactory $summaryFactory,
        private readonly LicenseGate $licenseGate,
        private readonly LicenseVerdictMessageFormatter $licenseVerdictMessageFormatter,
        private readonly HealthCheckBanner $healthCheckBanner,
        private readonly ProjectChangeIndicator $projectChangeIndicator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/resolve-lines', name: 'lexware_sync_triage_resolve_lines', methods: ['GET'])]
    public function show(int $id): Response
    {
        $orderConfirmation = $this->guardedOrderConfirmation($id);
        if ($orderConfirmation instanceof RedirectResponse) {
            return $orderConfirmation;
        }

        $rawPayload = $orderConfirmation->getRawPayload();
        $entries = $this->differ->diff($orderConfirmation, LexwarePayload::fromJson($rawPayload));

        $pageSetup = new PageSetup('lexware_sync.resolve_lines.title');
        $pageSetup->setTranslationDomain('messages');

        $verdict = $this->licenseGate->verdict();

        return $this->render('@KimaiLexwareSync/triage/resolve_lines.html.twig', [
            'page_setup' => $pageSetup,
            'orderConfirmation' => $orderConfirmation,
            'entries' => $entries,
            'currency' => $this->summaryFactory->fromRawPayload($rawPayload)->currency,
            'deriveBudgetEnabled' => $this->configuration->isDeriveBudgetEnabled(),
            'licenseState' => $verdict->state->key(),
            'licenseMessage' => $this->licenseVerdictMessageFormatter->format($verdict),
            'healthCheckMessages' => $this->healthCheckBanner->messages(),
        ]);
    }

    #[Route(path: '/resolve-lines', name: 'lexware_sync_triage_apply_lines', methods: ['POST'])]
    public function apply(int $id, Request $request): RedirectResponse
    {
        $orderConfirmation = $this->guardedOrderConfirmation($id);
        if ($orderConfirmation instanceof RedirectResponse) {
            return $orderConfirmation;
        }

        if (!$this->isCsrfTokenValid('lexware_sync_triage', $request->request->getString('_token'))) {
            return $this->redirectToRoute('lexware_sync_triage');
        }

        $positions = [];
        foreach ($request->request->all('positions') as $rawPosition) {
            if (is_numeric($rawPosition)) {
                $positions[] = (int) $rawPosition;
            }
        }

        $this->entityManager->beginTransaction();
        try {
            $this->resolver->apply(
                $orderConfirmation,
                LexwarePayload::fromJson($orderConfirmation->getRawPayload()),
                $positions,
                $this->configuration->getLineRegex(),
            );
            $orderConfirmation->clearChangedAfterConversion();
            $this->repository->save($orderConfirmation);
            $this->projectChangeIndicator->synchronize($orderConfirmation);

            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        $this->addFlash('success', 'lexware_sync.triage.lines_resolved');

        return $this->redirectToRoute('lexware_sync_triage');
    }

    private function guardedOrderConfirmation(int $id): TrackedOrderConfirmation|RedirectResponse
    {
        $orderConfirmation = $this->repository->find($id);
        if (
            $orderConfirmation === null
            || !$orderConfirmation->getStatus()->isConverted()
            || !$this->configuration->isReadLinesEnabled()
        ) {
            return $this->redirectToRoute('lexware_sync_triage');
        }

        return $orderConfirmation;
    }
}
