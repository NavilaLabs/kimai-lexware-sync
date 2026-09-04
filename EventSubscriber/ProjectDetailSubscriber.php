<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\EventSubscriber;

use App\Event\ProjectDetailControllerEvent;
use KimaiPlugin\KimaiLexwareSyncBundle\Controller\ProjectOriginController;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ProjectDetailSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly TrackedOrderConfirmationRepository $repository)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ProjectDetailControllerEvent::class => ['onProjectDetail', 100]];
    }

    public function onProjectDetail(ProjectDetailControllerEvent $event): void
    {
        $orderConfirmation = $this->repository->findOneBy(['project' => $event->getProject()]);
        if ($orderConfirmation === null) {
            return;
        }

        $event->addController(ProjectOriginController::class . '::show');
    }
}
