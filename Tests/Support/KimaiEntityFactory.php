<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class KimaiEntityFactory
{
    private int $sequence = 0;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function createCustomer(string $name = 'Test customer'): Customer
    {
        $customer = new Customer($name);
        $customer->setCountry('DE');
        $customer->setCurrency('EUR');
        $customer->setTimezone('Europe/Berlin');

        return $this->save($customer);
    }

    public function createProject(?Customer $customer = null, string $name = 'Test project'): Project
    {
        $project = new Project();
        $project->setName($name);
        $project->setCustomer($customer ?? $this->createCustomer());

        return $this->save($project);
    }

    public function createActivity(?Project $project = null, string $name = 'Test activity'): Activity
    {
        $activity = new Activity();
        $activity->setName($name);
        $activity->setProject($project);

        return $this->save($activity);
    }

    /**
     * @param list<string> $roles
     */
    public function createUser(string $username = 'tester', array $roles = [User::ROLE_USER]): User
    {
        $user = new User();
        $user->setUserIdentifier($username);
        $user->setEmail($username . '@example.com');
        $user->setPassword('irrelevant-for-tests');
        $user->setRoles($roles);
        $user->setEnabled(true);

        // Kimai sends a user who has not seen its onboarding wizards to those wizards, which
        // would answer every request in a test with a redirect instead of the expected page.
        foreach (User::WIZARDS as $wizard) {
            $user->setWizardAsSeen($wizard);
        }

        return $this->save($user);
    }

    public function createTimesheet(
        Project $project,
        Activity $activity,
        User $user,
        int $durationSeconds = 3600,
        ?float $hourlyRate = 100.0,
    ): Timesheet {
        $timesheet = new Timesheet();
        $timesheet->setUser($user);
        $timesheet->setProject($project);
        $timesheet->setActivity($activity);
        $timesheet->setBegin(new \DateTime('2026-09-01 09:00:00'));
        $timesheet->setEnd(new \DateTime('2026-09-01 09:00:00 +' . $durationSeconds . ' seconds'));
        $timesheet->setDuration($durationSeconds);
        $timesheet->setHourlyRate($hourlyRate);
        $timesheet->setRate($hourlyRate === null ? 0.0 : $hourlyRate * $durationSeconds / 3600);

        return $this->save($timesheet);
    }

    public function uniqueName(string $prefix): string
    {
        return $prefix . ' ' . ++$this->sequence;
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function save(object $entity): object
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $entity;
    }
}
