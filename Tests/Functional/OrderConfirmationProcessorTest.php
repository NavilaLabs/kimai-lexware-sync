<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Dto\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\ContactMapping;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Exception\UnprocessableOrderConfirmationException;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\ContactMappingRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationProcessor;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FunctionalTestCase;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\SignsLicenseArtefacts;

final class OrderConfirmationProcessorTest extends FunctionalTestCase
{
    use SignsLicenseArtefacts;

    public function testConversionCreatesCustomerProjectAndActivitiesForMatchingLines(): void
    {
        $this->givenAConfirmedLicense();
        $orderConfirmation = $this->trackedOrderConfirmation('lexware-id-1', 'AB-2026-001', 'Contact GmbH');

        $this->processor()->convert($orderConfirmation, new LexwarePayload($this->payload()), null, '', true);
        $this->entityManager()->flush();

        $project = $orderConfirmation->getProject();
        self::assertInstanceOf(Project::class, $project);
        self::assertSame('AB-2026-001', $project->getName());

        $customer = $orderConfirmation->getCustomer();
        self::assertInstanceOf(Customer::class, $customer);
        self::assertSame('Contact GmbH', $customer->getName());

        $activityNames = array_map(
            static fn (Activity $activity): string => $activity->getName() ?? '',
            $this->entityManager()->getRepository(Activity::class)->findBy(['project' => $project])
        );
        sort($activityNames);

        self::assertSame(['Consulting', 'Development'], $activityNames);
    }

    public function testAnExistingContactMappingReusesItsCustomerInsteadOfCreatingASecondOne(): void
    {
        $this->givenAConfirmedLicense();
        $existingCustomer = $this->factory()->createCustomer('Already known GmbH');
        $this->service(ContactMappingRepository::class)->save(new ContactMapping('contact-1', $existingCustomer));

        $orderConfirmation = $this->trackedOrderConfirmation('lexware-id-2', 'AB-2026-002', 'Contact GmbH');

        $this->processor()->convert($orderConfirmation, new LexwarePayload($this->payload()), null, '', false);
        $this->entityManager()->flush();

        self::assertSame($existingCustomer->getId(), $orderConfirmation->getCustomer()?->getId());
        self::assertSame([], $this->entityManager()->getRepository(Customer::class)->findBy(['name' => 'Contact GmbH']));
    }

    public function testTheLineRegularExpressionDecidesWhichLinesBecomeActivities(): void
    {
        $this->givenAConfirmedLicense();
        $orderConfirmation = $this->trackedOrderConfirmation('lexware-id-3', 'AB-2026-003', 'Contact GmbH');

        $this->processor()->convert($orderConfirmation, new LexwarePayload($this->payload()), null, '/^Development$/', true);
        $this->entityManager()->flush();

        $activities = $this->entityManager()->getRepository(Activity::class)->findBy(['project' => $orderConfirmation->getProject()]);

        self::assertCount(1, $activities);
        self::assertSame('Development', $activities[0]->getName());
    }

    public function testConversionPopulatesOrderNumberOrderDateAndComment(): void
    {
        $this->givenAConfirmedLicense();
        $orderConfirmation = $this->trackedOrderConfirmation('lexware-id-order-metadata', 'AB-2026-050', 'Contact GmbH');

        $this->processor()->convert($orderConfirmation, new LexwarePayload($this->payload()), null, '', false);
        $this->entityManager()->flush();

        $project = $orderConfirmation->getProject();
        self::assertInstanceOf(Project::class, $project);
        self::assertSame('AB-2026-050', $project->getOrderNumber());
        self::assertEquals(new \DateTime('2026-09-01'), $project->getOrderDate());
        self::assertSame('Order confirmation for a test', $project->getComment());
    }

    public function testAVoucherNumberLongerThanFiftyCharactersIsUnprocessable(): void
    {
        $this->givenAConfirmedLicense();
        $orderConfirmation = $this->trackedOrderConfirmation('lexware-id-long-voucher', str_repeat('A', 51), 'Contact GmbH');

        $this->expectException(UnprocessableOrderConfirmationException::class);

        $this->processor()->convert($orderConfirmation, new LexwarePayload($this->payload()), null, '', false);
    }

    public function testTheOrderConfirmationTitleCanBeUsedAsTheProjectName(): void
    {
        $this->givenAConfirmedLicense();
        $this->configure('lexware_sync.project_title_source', LexwareSyncConfiguration::PROJECT_TITLE_ORDER_CONFIRMATION_TITLE);
        $orderConfirmation = $this->trackedOrderConfirmation('lexware-id-title-source', 'AB-2026-060', 'Contact GmbH');

        $this->processor()->convert($orderConfirmation, new LexwarePayload($this->payload()), null, '', false);
        $this->entityManager()->flush();

        self::assertSame('Order confirmation for a test', $orderConfirmation->getProject()?->getName());
    }

    public function testCustomerAndTitleCanBeCombinedAsTheProjectName(): void
    {
        $this->givenAConfirmedLicense();
        $this->configure('lexware_sync.project_title_source', LexwareSyncConfiguration::PROJECT_TITLE_CUSTOMER_AND_TITLE);
        $orderConfirmation = $this->trackedOrderConfirmation('lexware-id-title-source-2', 'AB-2026-061', 'Contact GmbH');

        $this->processor()->convert($orderConfirmation, new LexwarePayload($this->payload()), null, '', false);
        $this->entityManager()->flush();

        self::assertSame('Contact GmbH - Order confirmation for a test', $orderConfirmation->getProject()?->getName());
    }

    public function testAnUnusableTitleFallsBackToTheVoucherNumber(): void
    {
        $this->givenAConfirmedLicense();
        $this->configure('lexware_sync.project_title_source', LexwareSyncConfiguration::PROJECT_TITLE_ORDER_CONFIRMATION_TITLE);
        $orderConfirmation = $this->trackedOrderConfirmation('lexware-id-title-source-3', 'AB-2026-062', 'Contact GmbH');
        $orderConfirmation->updateFromLexwarePayload(
            'AB-2026-062',
            'Title with a disallowed " character',
            new \DateTimeImmutable('2026-09-01'),
            'contact-1',
            'Contact GmbH',
            json_encode($this->payload(), \JSON_THROW_ON_ERROR),
            null,
        );
        $this->entityManager()->flush();

        $this->processor()->convert($orderConfirmation, new LexwarePayload($this->payload()), null, '', false);
        $this->entityManager()->flush();

        self::assertSame('AB-2026-062', $orderConfirmation->getProject()?->getName());
    }

    public function testDerivedBudgetSumsOnlyTheHourLinesOntoTheirActivitiesAndTheProject(): void
    {
        $this->givenAConfirmedLicense();
        $this->configure('lexware_sync.derive_budget_enabled', true);
        $orderConfirmation = $this->trackedOrderConfirmation('lexware-id-budget-1', 'AB-2026-100', 'Contact GmbH');

        $this->processor()->convert($orderConfirmation, new LexwarePayload($this->budgetedPayload()), null, '', true);
        $this->entityManager()->flush();

        $project = $orderConfirmation->getProject();
        self::assertInstanceOf(Project::class, $project);
        self::assertSame(1200.0, $project->getBudget());
        self::assertSame(43200, $project->getTimeBudget());

        $activities = $this->entityManager()->getRepository(Activity::class)->findBy(['project' => $project]);
        $byName = [];
        foreach ($activities as $activity) {
            $byName[$activity->getName()] = $activity;
        }

        self::assertSame(28800, $byName['Development']->getTimeBudget());
        self::assertSame(800.0, $byName['Development']->getBudget());
        self::assertSame(0, $byName['Material']->getTimeBudget());
        self::assertSame(0.0, $byName['Material']->getBudget());
    }

    public function testDerivedBudgetIsNeverSetWhenTheSettingIsOff(): void
    {
        $this->givenAConfirmedLicense();
        $orderConfirmation = $this->trackedOrderConfirmation('lexware-id-budget-2', 'AB-2026-101', 'Contact GmbH');

        $this->processor()->convert($orderConfirmation, new LexwarePayload($this->budgetedPayload()), null, '', true);
        $this->entityManager()->flush();

        self::assertSame(0.0, $orderConfirmation->getProject()?->getBudget());
    }

    public function testTheProjectBudgetCountsAnHourLineThatTheLineRegexDidNotMatch(): void
    {
        $this->givenAConfirmedLicense();
        $this->configure('lexware_sync.derive_budget_enabled', true);
        $orderConfirmation = $this->trackedOrderConfirmation('lexware-id-budget-3', 'AB-2026-102', 'Contact GmbH');

        $this->processor()->convert($orderConfirmation, new LexwarePayload($this->budgetedPayload()), null, '/^Development$/', true);
        $this->entityManager()->flush();

        $project = $orderConfirmation->getProject();
        self::assertInstanceOf(Project::class, $project);

        $activities = $this->entityManager()->getRepository(Activity::class)->findBy(['project' => $project]);
        self::assertCount(1, $activities, 'Only the line the regex matched becomes an activity.');

        self::assertSame(43200, $project->getTimeBudget(), 'Consulting is an hour line too, so the project budget covers it even without an activity.');
        self::assertSame(1200.0, $project->getBudget());
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetedPayload(): array
    {
        return [
            'address' => ['contactId' => 'contact-1', 'name' => 'Contact GmbH'],
            'lineItems' => [
                ['type' => 'custom', 'name' => 'Development', 'description' => 'Building the thing', 'quantity' => 8, 'unitName' => 'Stunden', 'lineItemAmount' => 800.0],
                ['type' => 'custom', 'name' => 'Consulting', 'description' => 'Talking about the thing', 'quantity' => 4, 'unitName' => 'Stunden', 'lineItemAmount' => 400.0],
                ['type' => 'custom', 'name' => 'Material', 'description' => 'A physical thing', 'quantity' => 2, 'unitName' => 'Stück', 'lineItemAmount' => 100.0],
            ],
        ];
    }

    private function processor(): OrderConfirmationProcessor
    {
        return $this->service(OrderConfirmationProcessor::class);
    }

    private function trackedOrderConfirmation(string $lexwareId, string $voucherNumber, string $contactName): TrackedOrderConfirmation
    {
        $orderConfirmation = new TrackedOrderConfirmation($lexwareId);
        $orderConfirmation->updateFromLexwarePayload(
            $voucherNumber,
            'Order confirmation for a test',
            new \DateTimeImmutable('2026-09-01'),
            'contact-1',
            $contactName,
            json_encode($this->payload(), JSON_THROW_ON_ERROR),
            null
        );

        $this->entityManager()->persist($orderConfirmation);
        $this->entityManager()->flush();

        return $orderConfirmation;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'address' => ['contactId' => 'contact-1', 'name' => 'Contact GmbH'],
            'lineItems' => [
                ['type' => 'custom', 'name' => 'Development', 'description' => 'Building the thing'],
                ['type' => 'custom', 'name' => 'Consulting', 'description' => 'Talking about the thing'],
                ['type' => 'text', 'name' => 'Thank you for your order', 'description' => null],
            ],
        ];
    }
}
