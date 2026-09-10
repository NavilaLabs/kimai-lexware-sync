<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\EventSubscriber;

use App\Entity\MetaTableTypeInterface;
use App\Entity\ProjectMeta;
use App\Event\ProjectMetaDefinitionEvent;
use App\Event\ProjectMetaDisplayEvent;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\ProjectChangeIndicator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;

final class ProjectMetaFieldSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ProjectMetaDefinitionEvent::class => ['loadMeta', 200],
            ProjectMetaDisplayEvent::class => ['displayMeta', 200],
        ];
    }

    public function loadMeta(ProjectMetaDefinitionEvent $event): void
    {
        $event->getEntity()->setMetaField($this->metaField());
    }

    public function displayMeta(ProjectMetaDisplayEvent $event): void
    {
        $event->addField($this->metaField());
    }

    private function metaField(): MetaTableTypeInterface
    {
        return (new ProjectMeta())
            ->setName(ProjectChangeIndicator::META_FIELD_NAME)
            ->setLabel('lexware_sync.project.changed_column')
            ->setType(TextType::class)
            ->setIsVisible(false);
    }
}
