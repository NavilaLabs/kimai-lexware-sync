<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Configuration;

final class LexwareSyncConfiguration
{
    public const PROJECT_COMPLETION_END_DATE = 'end_date';
    public const PROJECT_COMPLETION_HIDDEN = 'hidden';
    public const PROJECT_TITLE_VOUCHER_NUMBER = 'voucher_number';
    public const PROJECT_TITLE_ORDER_CONFIRMATION_TITLE = 'order_confirmation_title';
    public const PROJECT_TITLE_CUSTOMER_AND_TITLE = 'customer_and_title';

    public const DEFAULT_RECONCILE_INTERVAL_MINUTES = 30;
    public const DEFAULT_CHECK_API_KEY_INTERVAL_DAYS = 7;
    public const DEFAULT_CHECK_LICENSE_INTERVAL_DAYS = 1;
    public const DEFAULT_BUDGET_UNIT_REGEX = '/^(Stunden?|Std\.?|hours?|hrs?|h)$/i';
    public const DEFAULT_PROJECT_TITLE_SOURCE = self::PROJECT_TITLE_VOUCHER_NUMBER;
    public const DEFAULT_PROJECT_COMPLETION_MODE = self::PROJECT_COMPLETION_END_DATE;

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

        return $value === self::PROJECT_COMPLETION_HIDDEN ? self::PROJECT_COMPLETION_HIDDEN : self::DEFAULT_PROJECT_COMPLETION_MODE;
    }

    public function getLicenseKey(): string
    {
        return $this->readText('lexware_sync.license_key');
    }

    public function getCheckApiKeyIntervalDays(): int
    {
        $days = (int) $this->readText('lexware_sync.check_api_key_interval_days');

        return $days > 0 ? $days : self::DEFAULT_CHECK_API_KEY_INTERVAL_DAYS;
    }

    public function getCheckLicenseIntervalDays(): int
    {
        $days = (int) $this->readText('lexware_sync.check_license_interval_days');

        return $days > 0 ? $days : self::DEFAULT_CHECK_LICENSE_INTERVAL_DAYS;
    }

    public function getProjectTitleSource(): string
    {
        $value = $this->readText('lexware_sync.project_title_source');

        return match ($value) {
            self::PROJECT_TITLE_ORDER_CONFIRMATION_TITLE, self::PROJECT_TITLE_CUSTOMER_AND_TITLE => $value,
            default => self::DEFAULT_PROJECT_TITLE_SOURCE,
        };
    }

    public function isDeriveBudgetEnabled(): bool
    {
        return $this->readFlag('lexware_sync.derive_budget_enabled');
    }

    public function getBudgetUnitRegex(): string
    {
        $value = $this->readText('lexware_sync.budget_unit_regex');

        return $value !== '' ? $value : self::DEFAULT_BUDGET_UNIT_REGEX;
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
