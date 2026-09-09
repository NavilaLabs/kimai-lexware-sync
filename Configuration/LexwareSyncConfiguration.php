<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Configuration;

final class LexwareSyncConfiguration
{
    public const PROJECT_COMPLETION_END_DATE = 'end_date';
    public const PROJECT_COMPLETION_HIDDEN = 'hidden';

    private const DEFAULT_RECONCILE_INTERVAL_MINUTES = 30;

    public function __construct(private readonly SettingReader $settingReader)
    {
    }

    public function getApiKey(): string
    {
        return $this->readText('lexware_sync.api_key');
    }

    public function getPublicBaseUrl(): string
    {
        return rtrim($this->readText('lexware_sync.public_base_url'), '/');
    }

    public function isAutoConvertEnabled(): bool
    {
        return $this->readFlag('lexware_sync.auto_convert_enabled');
    }

    public function getTitleRegex(): string
    {
        return $this->readText('lexware_sync.title_regex');
    }

    public function isReadLinesEnabled(): bool
    {
        return $this->readFlag('lexware_sync.read_lines_enabled');
    }

    public function getLineRegex(): string
    {
        return $this->readText('lexware_sync.line_regex');
    }

    public function getReconcileIntervalMinutes(): int
    {
        $minutes = (int) $this->readText('lexware_sync.reconcile_interval_minutes');

        return $minutes > 0 ? $minutes : self::DEFAULT_RECONCILE_INTERVAL_MINUTES;
    }

    public function getInvoiceTitleRegex(): string
    {
        return $this->readText('lexware_sync.invoice_title_regex');
    }

    public function getProjectCompletionMode(): string
    {
        $value = $this->readText('lexware_sync.project_completion_mode');

        return $value === self::PROJECT_COMPLETION_HIDDEN ? self::PROJECT_COMPLETION_HIDDEN : self::PROJECT_COMPLETION_END_DATE;
    }

    public function getLicenseKey(): string
    {
        return $this->readText('lexware_sync.license_key');
    }

    private function readText(string $name): string
    {
        return $this->settingReader->read($name) ?? '';
    }

    private function readFlag(string $name): bool
    {
        $value = $this->settingReader->read($name);

        return $value !== null && $value !== '' && $value !== '0';
    }
}
