<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional;

use App\Entity\Project;
use App\Entity\Team;
use App\Entity\User;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\ProjectChangeIndicator;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\WebTestCase;

final class ProjectOriginControllerTest extends WebTestCase
{
    public function testTheProjectShowsItsOrderConfirmationWithoutAResolveButtonWhileNothingChanged(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->configure('lexware_sync.read_lines_enabled', true);
        [$project] = $this->givenAConvertedProject(false);

        $crawler = $browser->request('GET', $this->url('project_details', ['id' => $project->getId()]));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('AB-2026-300', $crawler->filter('.lexware-sync-origin')->text());
        self::assertCount(0, $crawler->filter('.lexware-sync-origin')->siblings()->filter('a.btn-warning'));
    }

    public function testAChangedOrderConfirmationOffersTheResolveButtonOnTheProject(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->configure('lexware_sync.read_lines_enabled', true);
        [$project, $orderConfirmation] = $this->givenAConvertedProject(true);

        $crawler = $browser->request('GET', $this->url('project_details', ['id' => $project->getId()]));

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filter('a[href="' . $this->url('lexware_sync_triage_resolve_lines', ['id' => $orderConfirmation->getId()]) . '"]')
        );
    }

    public function testTheResolveButtonStaysHiddenWhileLineReadingIsOff(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        [$project, $orderConfirmation] = $this->givenAConvertedProject(true);

        $crawler = $browser->request('GET', $this->url('project_details', ['id' => $project->getId()]));

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('a[href="' . $this->url('lexware_sync_triage_resolve_lines', ['id' => $orderConfirmation->getId()]) . '"]')
        );
    }

    public function testAProjectViewerWithoutTheLexwarePermissionSeesTheWarningButNoButton(): void
    {
        $browser = $this->browser();
        $this->configure('lexware_sync.read_lines_enabled', true);
        [$project, $orderConfirmation] = $this->givenAConvertedProject(true);

        $teamlead = $this->factory()->createUser('teamlead', [User::ROLE_TEAMLEAD]);
        $team = new Team('Team without Lexware access');
        $team->addTeamlead($teamlead);
        $team->addProject($project);
        $this->entityManager()->persist($team);
        $this->entityManager()->flush();

        $browser->loginUser($teamlead, 'secured_area');

        $crawler = $browser->request('GET', $this->url('project_details', ['id' => $project->getId()]));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('AB-2026-300', $crawler->filter('.lexware-sync-origin')->text());
        self::assertCount(
            0,
            $crawler->filter('a[href="' . $this->url('lexware_sync_triage_resolve_lines', ['id' => $orderConfirmation->getId()]) . '"]'),
            'A link that would only answer 403 is worse than no link.'
        );
    }

    public function testTheProjectListCarriesTheChangeMarkerAsItsOwnColumn(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        [, $orderConfirmation] = $this->givenAConvertedProject(true);
        $this->service(ProjectChangeIndicator::class)->synchronize($orderConfirmation);

        $crawler = $browser->request('GET', $this->url('admin_project'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            '⚠',
            $crawler->filter('table')->text(),
            'The change marker did not reach the project list. Its column ships hidden behind Kimai\'s column picker, so this asserts on the rendered markup, not on what a fresh user sees straight away.'
        );
    }

    /**
     * @return array{0: Project, 1: TrackedOrderConfirmation}
     */
    private function givenAConvertedProject(bool $changedAfterConversion): array
    {
        $project = $this->factory()->createProject();

        $orderConfirmation = new TrackedOrderConfirmation('lexware-project-origin-' . $project->getId());
        $orderConfirmation->updateFromLexwarePayload(
            'AB-2026-300',
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
                'AB-2026-300',
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
}
