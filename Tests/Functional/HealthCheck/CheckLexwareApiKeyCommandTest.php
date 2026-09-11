<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\HealthCheck;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck\HealthCheckResultStore;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckLexwareApiKeyCommandTest extends FunctionalTestCase
{
    public function testASuccessfulCallIsRecordedAsPassing(): void
    {
        $this->lexware()->willRespondWith('GET', '/v1/voucherlist', ['content' => [], 'last' => true]);

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $result = $this->service(HealthCheckResultStore::class)->latest('api_key');
        self::assertNotNull($result);
        self::assertTrue($result->ok);
    }

    public function testAFailingCallIsRecordedAsFailingWithItsMessage(): void
    {
        $this->lexware()->willRespondWith('GET', '/v1/voucherlist', ['message' => 'invalid token'], 401);

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $result = $this->service(HealthCheckResultStore::class)->latest('api_key');
        self::assertNotNull($result);
        self::assertFalse($result->ok);
        self::assertNotNull($result->message);
    }

    private function runCommand(): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('kimai:lexware-sync:check-api-key'));
        $tester->execute([]);

        return $tester;
    }
}
