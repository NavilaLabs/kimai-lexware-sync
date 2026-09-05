<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Controller;

use App\Controller\AbstractController;
use App\Utils\PageSetup;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\CustomerCurrencyMismatchException;
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
        private readonly EntityManagerInterface $entityManager,
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
    public function convert(int $id, Request $request): RedirectResponse
    {
        $orderConfirmation = $this->repository->find($id);
        if ($orderConfirmation === null || !$orderConfirmation->getStatus()->isPending()) {
            return $this->redirectToRoute('lexware_sync_triage');
        }

        if (!$this->isCsrfTokenValid('lexware_sync_triage', $request->request->get('_token'))) {
            return $this->redirectToRoute('lexware_sync_triage');
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

        return $this->redirectToRoute('lexware_sync_triage');
    }

    #[Route(path: '/{id}/reject', name: 'lexware_sync_triage_reject', methods: ['POST'])]
    public function reject(int $id, Request $request): RedirectResponse
    {
        $orderConfirmation = $this->repository->find($id);
        if ($orderConfirmation === null) {
            return $this->redirectToRoute('lexware_sync_triage');
        }

        if (!$this->isCsrfTokenValid('lexware_sync_triage', $request->request->get('_token'))) {
            return $this->redirectToRoute('lexware_sync_triage');
        }

        $orderConfirmation->markRejected($this->getUser());
        $this->repository->save($orderConfirmation);

        return $this->redirectToRoute('lexware_sync_triage');
    }
}
