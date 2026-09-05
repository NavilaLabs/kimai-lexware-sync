<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\EventSubscriber;

use App\Event\PageActionsEvent;
use App\EventSubscriber\Actions\AbstractActionsSubscriber;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InvoiceActionSubscriber extends AbstractActionsSubscriber
{
    public function __construct(
        AuthorizationCheckerInterface $auth,
        UrlGeneratorInterface $urlGenerator,
        private readonly TrackedInvoiceRepository $repository,
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
        $label = $this->translator->trans('lexware_sync.invoice.action') . ' (' . $pendingCount . ')';

        $event->addAction('lexware_sync_invoices', [
            'url' => $this->path('lexware_sync_invoices'),
            'class' => '',
            'title' => $label,
            'icon' => 'fas fa-file-invoice-dollar',
        ]);
    }
}
