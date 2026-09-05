<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\EventSubscriber;

use App\Event\PageActionsEvent;
use App\EventSubscriber\Actions\AbstractActionsSubscriber;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TriageActionSubscriber extends AbstractActionsSubscriber
{
    public function __construct(
        AuthorizationCheckerInterface $auth,
        UrlGeneratorInterface $urlGenerator,
        private readonly TrackedOrderConfirmationRepository $repository,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($auth, $urlGenerator);
    }

    public static function getActionName(): string
    {
        return 'projects';
    }

    public function onActions(PageActionsEvent $event): void
    {
        if (!$this->isGranted('manage_lexware_sync')) {
            return;
        }

        $pendingCount = $this->repository->countPending();
        $label = $this->translator->trans('lexware_sync.triage.action') . ' (' . $pendingCount . ')';

        $event->addAction('lexware_sync_triage', [
            'url' => $this->path('lexware_sync_triage'),
            'class' => '',
            'title' => $label,
            'icon' => 'fas fa-file-import',
        ]);
    }
}
