<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\EventSubscriber;

use App\Event\ConfigureMainMenuEvent;
use App\Utils\MenuItemModel;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class MenuSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AuthorizationCheckerInterface $security,
        private readonly TrackedOrderConfirmationRepository $orderConfirmationRepository,
        private readonly TrackedInvoiceRepository $trackedInvoiceRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [ConfigureMainMenuEvent::class => ['onMenuConfigure', 100]];
    }

    public function onMenuConfigure(ConfigureMainMenuEvent $event): void
    {
        if (!$this->security->isGranted('IS_AUTHENTICATED_REMEMBERED') || !$this->security->isGranted('manage_lexware_sync')) {
            return;
        }

        $menu = new MenuItemModel('lexware_sync', 'lexware_sync.menu', null, [], 'fas fa-file-invoice');

        $orderConfirmations = new MenuItemModel(
            'lexware_sync_order_confirmations',
            'lexware_sync.triage.title',
            'lexware_sync_triage',
            [],
            'fas fa-file-import',
        );
        $this->addPendingBadge($orderConfirmations, $this->orderConfirmationRepository->countPending());
        $menu->addChild($orderConfirmations);

        $invoices = new MenuItemModel(
            'lexware_sync_invoice_drafts',
            'lexware_sync.invoice.title',
            'lexware_sync_invoices',
            [],
            'fas fa-file-invoice-dollar',
        );
        $invoices->addChildRoute('lexware_sync_invoices_assign');
        $this->addPendingBadge($invoices, $this->trackedInvoiceRepository->countPending());
        $menu->addChild($invoices);

        $event->getMenu()->addChild($menu);
    }

    private function addPendingBadge(MenuItemModel $item, int $pendingCount): void
    {
        if ($pendingCount === 0) {
            return;
        }

        $item->setBadge((string) $pendingCount);
        $item->setBadgeColor('yellow');
    }
}
