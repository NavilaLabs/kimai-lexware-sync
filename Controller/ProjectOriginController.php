<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\Project;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ProjectOriginController extends AbstractController
{
    public function __construct(
        private readonly TrackedOrderConfirmationRepository $repository,
        private readonly LexwareSyncConfiguration $configuration,
    ) {
    }

    #[IsGranted('view', 'project')]
    public function show(Project $project): Response
    {
        $orderConfirmation = $this->repository->findOneBy(['project' => $project]);
        if ($orderConfirmation === null) {
            return new Response('');
        }

        return $this->render('@KimaiLexwareSync/project/origin.html.twig', [
            'orderConfirmation' => $orderConfirmation,
            'readLinesEnabled' => $this->configuration->isReadLinesEnabled(),
        ]);
    }
}
