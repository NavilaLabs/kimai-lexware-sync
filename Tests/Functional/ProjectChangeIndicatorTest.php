<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional;

use App\Entity\Project;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\ProjectChangeIndicator;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FunctionalTestCase;

final class ProjectChangeIndicatorTest extends FunctionalTestCase
{
    public function testAConvertedProjectWithoutChangesCarriesNoMarker(): void
    {
        [$project, $orderConfirmation] = $this->givenAConvertedOrderConfirmation(false);

        $this->indicator()->synchronize($orderConfirmation);

        self::assertNull(
            $project->getMetaField(ProjectChangeIndicator::META_FIELD_NAME),
            'A project with nothing to report should not collect an empty meta row.'
        );
    }

    public function testAChangedOrderConfirmationMarksItsProject(): void
    {
        [$project, $orderConfirmation] = $this->givenAConvertedOrderConfirmation(true);

        $this->indicator()->synchronize($orderConfirmation);

        self::assertSame('⚠', $this->markerOn($project));
    }

    public function testResolvingTheChangeClearsTheMarkerAgain(): void
    {
        [$project, $orderConfirmation] = $this->givenAConvertedOrderConfirmation(true);
        $this->indicator()->synchronize($orderConfirmation);

        $orderConfirmation->clearChangedAfterConversion();
        $this->indicator()->synchronize($orderConfirmation);

        self::assertSame('', $this->markerOn($project));
    }

    public function testSynchronizeAllRepairsAProjectThatWasNeverMarked(): void
    {
        [$project, $orderConfirmation] = $this->givenAConvertedOrderConfirmation(true);
        self::assertNull($project->getMetaField(ProjectChangeIndicator::META_FIELD_NAME), 'This test only means something while the marker is genuinely absent.');

        $this->indicator()->synchronizeAll();

        self::assertSame('⚠', $this->markerOn($project));
        self::assertTrue($orderConfirmation->hasChangedAfterConversion());
    }

    public function testTheStoredMarkerCarriesNoLanguageOfItsOwn(): void
    {
        [$project, $orderConfirmation] = $this->givenAConvertedOrderConfirmation(true);

        $this->indicator()->synchronize($orderConfirmation);

        $marker = $this->markerOn($project);
        self::assertSame(
            $marker,
            preg_replace('/[a-zA-Z]/', '', $marker),
            'A meta value is printed verbatim, so a word in it would freeze whichever language was active when the row was written.'
        );
    }

    public function testAnOrderConfirmationWithoutAProjectIsIgnored(): void
    {
        $orderConfirmation = new TrackedOrderConfirmation('lexware-indicator-no-project');
        $orderConfirmation->updateFromLexwarePayload(
            'AB-2026-401',
            'Order confirmation for a test',
            new \DateTimeImmutable('2026-09-01'),
            'contact-1',
            'Contact GmbH',
            '{"lineItems":[]}',
            null
        );
        $this->entityManager()->persist($orderConfirmation);
        $this->entityManager()->flush();

        $this->indicator()->synchronize($orderConfirmation);

        self::assertNull($orderConfirmation->getProject());
    }

    private function markerOn(Project $project): string
    {
        $field = $project->getMetaField(ProjectChangeIndicator::META_FIELD_NAME);
        self::assertNotNull($field, 'The project carries no Lexware marker field at all.');

        $value = $field->getValue();

        return \is_string($value) ? $value : '';
    }

    /**
     * @return array{0: Project, 1: TrackedOrderConfirmation}
     */
    private function givenAConvertedOrderConfirmation(bool $changedAfterConversion): array
    {
        $project = $this->factory()->createProject();

        $orderConfirmation = new TrackedOrderConfirmation('lexware-indicator-' . $project->getId());
        $orderConfirmation->updateFromLexwarePayload(
            'AB-2026-400',
            'Order confirmation for a test',
            new \DateTimeImmutable('2026-09-01'),
            'contact-1',
            'Contact GmbH',
            '{"lineItems":[]}',
            null
        );
        $orderConfirmation->setProject($project);
        $orderConfirmation->markAutomaticallyConverted();

        $this->entityManager()->persist($orderConfirmation);
        $this->entityManager()->flush();

        if ($changedAfterConversion) {
            $orderConfirmation->updateFromLexwarePayload(
                'AB-2026-400',
                'Order confirmation for a test',
                new \DateTimeImmutable('2026-09-01'),
                'contact-1',
                'Contact GmbH',
                '{"lineItems":[{"type":"custom","name":"Development"}]}',
                null
            );
            $this->entityManager()->flush();

            self::assertTrue($orderConfirmation->hasChangedAfterConversion());
        }

        return [$project, $orderConfirmation];
    }

    private function indicator(): ProjectChangeIndicator
    {
        return $this->service(ProjectChangeIndicator::class);
    }
}
