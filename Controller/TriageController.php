<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Controller;

use App\Controller\AbstractController;
use App\Utils\PageSetup;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\CustomerCurrencyMismatchException;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationProcessor;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/lexware-sync/triage')]
#[IsGranted('triage_lexware_sync')]
final class TriageController extends AbstractController
{
    public function __construct(
        private readonly TrackedOrderConfirmationRepository $repository,
        private readonly OrderConfirmationProcessor $processor,
        private readonly LexwareSyncConfiguration $configuration,
    ) {
    }

    #[Route(path: '', name: 'lexware_sync_triage', methods: ['GET'])]
    public function index(): Response
    {
        $page = new PageSetup('lexware_sync.triage.title');
        $page->setTranslationDomain('messages');

        return $this->render('@KimaiLexwareSync/triage/index.html.twig', [
            'page_setup' => $page,
            'orderConfirmations' => $this->repository->findPending(),
        ]);
    }

    #[Route(path: '/{id}/convert', name: 'lexware_sync_triage_convert', methods: ['POST'])]
    public function convert(int $id): RedirectResponse
    {
        $orderConfirmation = $this->repository->find($id);
        if ($orderConfirmation !== null) {
            $payload = json_decode($orderConfirmation->getRawPayload(), true);

            try {
                $this->processor->convert(
                    $orderConfirmation,
                    \is_array($payload) ? $payload : [],
                    $this->getUser(),
                    $this->configuration->getLineRegex(),
                    $this->configuration->isReadLinesEnabled(),
                );
                $this->repository->save($orderConfirmation);
            } catch (CustomerCurrencyMismatchException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('lexware_sync_triage');
    }

    #[Route(path: '/{id}/reject', name: 'lexware_sync_triage_reject', methods: ['POST'])]
    public function reject(int $id): RedirectResponse
    {
        $orderConfirmation = $this->repository->find($id);
        if ($orderConfirmation !== null) {
            $orderConfirmation->markRejected($this->getUser());
            $this->repository->save($orderConfirmation);
        }

        return $this->redirectToRoute('lexware_sync_triage');
    }
}
