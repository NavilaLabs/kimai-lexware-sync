<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional;

use App\Entity\User;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmationLine;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\SignsLicenseArtefacts;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\WebTestCase;

final class OrderConfirmationLineResolutionControllerTest extends WebTestCase
{
    use SignsLicenseArtefacts;

    public function testTheDiffScreenShowsAChangedLine(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->givenAConfirmedLicense();
        $this->configure('lexware_sync.read_lines_enabled', true);
        $this->configure('lexware_sync.derive_budget_enabled', true);
        $orderConfirmation = $this->givenAConvertedOrderConfirmationWithAChangedLine();

        $crawler = $browser->request('GET', $this->url('lexware_sync_triage_resolve_lines', ['id' => $orderConfirmation->getId()]));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-line-diff-status="changed"]'));
    }

    public function testSubmittingWithNothingCheckedStillClearsTheChangedFlag(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $browser->disableReboot();
        $this->givenAConfirmedLicense();
        $this->configure('lexware_sync.read_lines_enabled', true);
        $this->configure('lexware_sync.derive_budget_enabled', true);
        $orderConfirmation = $this->givenAConvertedOrderConfirmationWithAChangedLine();

        $id = $orderConfirmation->getId();
        $crawler = $browser->request('GET', $this->url('lexware_sync_triage_resolve_lines', ['id' => $id]));
        $form = $crawler->filter('form')->form();
        $form->setValues(['positions' => []]);
        $browser->submit($form);

        self::assertResponseRedirects();
        $reloaded = $this->entityManager()->getRepository(TrackedOrderConfirmation::class)->find($id);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->hasChangedAfterConversion());
    }

    public function testSubmittingAChosenPositionUpdatesTheActivityBudget(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $browser->disableReboot();
        $this->givenAConfirmedLicense();
        $this->configure('lexware_sync.read_lines_enabled', true);
        $this->configure('lexware_sync.derive_budget_enabled', true);
        $orderConfirmation = $this->givenAConvertedOrderConfirmationWithAChangedLine();

        $id = $orderConfirmation->getId();
        $crawler = $browser->request('GET', $this->url('lexware_sync_triage_resolve_lines', ['id' => $id]));
        $form = $crawler->filter('form')->form();
        $form->setValues(['positions' => ['0']]);
        $browser->submit($form);

        self::assertResponseRedirects();
        $reloaded = $this->entityManager()->getRepository(TrackedOrderConfirmation::class)->find($id);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->hasChangedAfterConversion());
        $line = $reloaded->getLines()->first();
        self::assertInstanceOf(TrackedOrderConfirmationLine::class, $line);
        self::assertSame(1200.0, $line->getNetAmount());
    }

    public function testTheDiffScreenIsReachableWithoutBudgetDerivationAndSaysSo(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->givenAConfirmedLicense();
        $this->configure('lexware_sync.read_lines_enabled', true);
        $orderConfirmation = $this->givenAConvertedOrderConfirmationWithAChangedLine();

        $crawler = $browser->request('GET', $this->url('lexware_sync_triage_resolve_lines', ['id' => $orderConfirmation->getId()]));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-line-diff-status="changed"]'));
        self::assertStringContainsString('Budget derivation is switched off', $crawler->filter('.alert-info')->text());
    }

    public function testTheDiffScreenRedirectsWhileLineReadingIsOff(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->givenAConfirmedLicense();
        $orderConfirmation = $this->givenAConvertedOrderConfirmationWithAChangedLine();

        $browser->request('GET', $this->url('lexware_sync_triage_resolve_lines', ['id' => $orderConfirmation->getId()]));

        self::assertResponseRedirects();
    }

    private function givenAConvertedOrderConfirmationWithAChangedLine(): TrackedOrderConfirmation
    {
        $project = $this->factory()->createProject();
        $activity = $this->factory()->createActivity($project, 'Development');

        $orderConfirmation = new TrackedOrderConfirmation('lexware-id-resolve-controller-' . $project->getId());
        $initialPayload = json_encode([
            'address' => ['contactId' => 'contact-1', 'name' => 'Contact GmbH'],
            'lineItems' => [
                ['type' => 'custom', 'name' => 'Development', 'quantity' => 8, 'unitName' => 'Stunden', 'lineItemAmount' => 800.0],
            ],
        ], \JSON_THROW_ON_ERROR);

        $orderConfirmation->updateFromLexwarePayload(
            'AB-2026-200',
            'Order confirmation for a test',
            new \DateTimeImmutable('2026-09-01'),
            'contact-1',
            'Contact GmbH',
            $initialPayload,
            null,
        );
        $orderConfirmation->setProject($project);
        $orderConfirmation->markAutomaticallyConverted();

        $line = new TrackedOrderConfirmationLine($orderConfirmation, 0, 'custom', 'Development', null, true, 8.0, 'Stunden', 800.0, true);
        $line->setActivity($activity);
        $orderConfirmation->addLine($line);

        $activity->setTimeBudget(28800);
        $activity->setBudget(800.0);
        $project->setTimeBudget(28800);
        $project->setBudget(800.0);

        $this->entityManager()->persist($orderConfirmation);
        $this->entityManager()->flush();

        $changedPayload = json_encode([
            'address' => ['contactId' => 'contact-1', 'name' => 'Contact GmbH'],
            'lineItems' => [
                ['type' => 'custom', 'name' => 'Development', 'quantity' => 12, 'unitName' => 'Stunden', 'lineItemAmount' => 1200.0],
            ],
        ], \JSON_THROW_ON_ERROR);

        $orderConfirmation->updateFromLexwarePayload(
            'AB-2026-200',
            'Order confirmation for a test',
            new \DateTimeImmutable('2026-09-01'),
            'contact-1',
            'Contact GmbH',
            $changedPayload,
            null,
        );
        $this->entityManager()->flush();

        self::assertTrue($orderConfirmation->hasChangedAfterConversion(), 'The fixture did not set up the changed flag it needs.');

        return $orderConfirmation;
    }
}
