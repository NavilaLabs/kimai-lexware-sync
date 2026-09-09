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
}
