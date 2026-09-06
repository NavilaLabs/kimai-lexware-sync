<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\ContactMapping;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\ContactMappingRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwarePayload;
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
