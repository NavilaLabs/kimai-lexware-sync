<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Configuration;

use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\InMemorySettingReader;
use PHPUnit\Framework\TestCase;

final class LexwareSyncConfigurationTest extends TestCase
{
    public function testAnUnsetSettingReadsAsItsDocumentedDefault(): void
    {
        $configuration = new LexwareSyncConfiguration(new InMemorySettingReader());

        self::assertSame('', $configuration->getApiKey());
        self::assertSame('', $configuration->getTitleRegex());
        self::assertSame('', $configuration->getLineRegex());
        self::assertFalse($configuration->isAutoConvertEnabled());
        self::assertFalse($configuration->isReadLinesEnabled());
        self::assertSame(30, $configuration->getReconcileIntervalMinutes());
        self::assertSame(LexwareSyncConfiguration::PROJECT_COMPLETION_END_DATE, $configuration->getProjectCompletionMode());
    }

    /**
     * Kimai writes a checkbox as the string "1" or the string "0", and the second one is the
     * reason this class cannot simply cast what it reads: "0" is a non empty string.
     */
    public function testACheckboxIsOnOnlyForTheStoredOne(): void
    {
        $reader = new InMemorySettingReader(['lexware_sync.read_lines_enabled' => '0']);
        self::assertFalse((new LexwareSyncConfiguration($reader))->isReadLinesEnabled());

        $reader->write('lexware_sync.read_lines_enabled', '1');
        self::assertTrue((new LexwareSyncConfiguration($reader))->isReadLinesEnabled());
    }

    public function testEverySettingIsReadAgainOnEveryCall(): void
    {
        $reader = new InMemorySettingReader(['lexware_sync.read_lines_enabled' => '0']);
        $configuration = new LexwareSyncConfiguration($reader);

        self::assertFalse($configuration->isReadLinesEnabled());

        $reader->write('lexware_sync.read_lines_enabled', '1');

        self::assertTrue($configuration->isReadLinesEnabled(), 'The configuration answered from a value it had remembered.');
    }

    public function testTheReconcileIntervalFallsBackWhenItIsNotAPositiveNumber(): void
    {
        self::assertSame(5, (new LexwareSyncConfiguration(new InMemorySettingReader(['lexware_sync.reconcile_interval_minutes' => '5'])))->getReconcileIntervalMinutes());
        self::assertSame(30, (new LexwareSyncConfiguration(new InMemorySettingReader(['lexware_sync.reconcile_interval_minutes' => '0'])))->getReconcileIntervalMinutes());
        self::assertSame(30, (new LexwareSyncConfiguration(new InMemorySettingReader(['lexware_sync.reconcile_interval_minutes' => '-1'])))->getReconcileIntervalMinutes());
        self::assertSame(30, (new LexwareSyncConfiguration(new InMemorySettingReader(['lexware_sync.reconcile_interval_minutes' => 'weekly'])))->getReconcileIntervalMinutes());
    }

    public function testThePublicBaseUrlLosesItsTrailingSlash(): void
    {
        $configuration = new LexwareSyncConfiguration(new InMemorySettingReader(['lexware_sync.public_base_url' => 'https://kimai.example.test/']));

        self::assertSame('https://kimai.example.test', $configuration->getPublicBaseUrl());
    }

    public function testTheHealthCheckIntervalsFallBackWhenNotAPositiveNumber(): void
    {
        $configuration = new LexwareSyncConfiguration(new InMemorySettingReader());

        self::assertSame(7, $configuration->getCheckApiKeyIntervalDays());
        self::assertSame(1, $configuration->getCheckLicenseIntervalDays());

        $withOverrides = new LexwareSyncConfiguration(new InMemorySettingReader([
            'lexware_sync.check_api_key_interval_days' => '3',
            'lexware_sync.check_license_interval_days' => '0',
        ]));

        self::assertSame(3, $withOverrides->getCheckApiKeyIntervalDays());
        self::assertSame(1, $withOverrides->getCheckLicenseIntervalDays());
    }

    public function testTheProjectTitleSourceDefaultsToTheVoucherNumber(): void
    {
        $configuration = new LexwareSyncConfiguration(new InMemorySettingReader());

        self::assertSame(LexwareSyncConfiguration::PROJECT_TITLE_VOUCHER_NUMBER, $configuration->getProjectTitleSource());
    }

    public function testAnUnknownProjectTitleSourceFallsBackToTheVoucherNumber(): void
    {
        $configuration = new LexwareSyncConfiguration(new InMemorySettingReader([
            'lexware_sync.project_title_source' => 'something_unexpected',
        ]));

        self::assertSame(LexwareSyncConfiguration::PROJECT_TITLE_VOUCHER_NUMBER, $configuration->getProjectTitleSource());
    }

    public function testDeriveBudgetIsDisabledByDefault(): void
    {
        $configuration = new LexwareSyncConfiguration(new InMemorySettingReader());

        self::assertFalse($configuration->isDeriveBudgetEnabled());
    }

    public function testDeriveBudgetCanBeEnabled(): void
    {
        $configuration = new LexwareSyncConfiguration(new InMemorySettingReader([
            'lexware_sync.derive_budget_enabled' => '1',
        ]));

        self::assertTrue($configuration->isDeriveBudgetEnabled());
    }

    public function testBudgetUnitRegexFallsBackToAHardcodedDefaultWhenUnset(): void
    {
        $configuration = new LexwareSyncConfiguration(new InMemorySettingReader());

        self::assertSame('/^(Stunden?|Std\.?|hours?|hrs?|h)$/i', $configuration->getBudgetUnitRegex());
    }

    public function testBudgetUnitRegexUsesTheStoredValueWhenSet(): void
    {
        $configuration = new LexwareSyncConfiguration(new InMemorySettingReader([
            'lexware_sync.budget_unit_regex' => '/^Arbeitsstunden$/',
        ]));

        self::assertSame('/^Arbeitsstunden$/', $configuration->getBudgetUnitRegex());
    }
}
