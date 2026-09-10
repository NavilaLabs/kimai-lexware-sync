<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use App\Entity\ProjectMeta;
use App\Repository\ProjectRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;

/**
 * Kimai's project list builds its columns in `ProjectController::indexAction()`, where a plugin
 * cannot add one. A meta field is the only opening, and its value has to live on the project
 * itself, so the flag that actually belongs to the tracked order confirmation is mirrored here.
 * Every write goes through this class, and `synchronizeAll()` repairs whatever drifted.
 *
 * The stored value is a bare warning sign rather than a sentence. Kimai prints a meta value
 * verbatim, so a translated sentence would freeze whichever language happened to be active when
 * the row was written, which for a console run is the installation default rather than the
 * language of the person later reading the list.
 */
final class ProjectChangeIndicator
{
    public const META_FIELD_NAME = 'lexware_sync_changed';

    private const MARKER = '⚠';

    public function __construct(
        private readonly TrackedOrderConfirmationRepository $orderConfirmationRepository,
        private readonly ProjectRepository $projectRepository,
    ) {
    }

    public function synchronize(TrackedOrderConfirmation $orderConfirmation): void
    {
        $project = $orderConfirmation->getProject();
        if ($project === null) {
            return;
        }

        $wanted = $orderConfirmation->hasChangedAfterConversion() && $orderConfirmation->getStatus()->isConverted()
            ? self::MARKER
            : '';

        $current = $project->getMetaField(self::META_FIELD_NAME);
        if ($current === null) {
            if ($wanted === '') {
                return;
            }

            $project->setMetaField((new ProjectMeta())->setName(self::META_FIELD_NAME)->setIsVisible(false));
            $current = $project->getMetaField(self::META_FIELD_NAME);
        }

        if ($current === null || $current->getValue() === $wanted) {
            return;
        }

        $current->setValue($wanted);
        $this->projectRepository->saveProject($project);
    }

    public function synchronizeAll(): void
    {
        foreach ($this->orderConfirmationRepository->findAllWithAProject() as $orderConfirmation) {
            $this->synchronize($orderConfirmation);
        }
    }
}
