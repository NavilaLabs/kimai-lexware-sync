<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Configuration;

use App\Configuration\SystemConfiguration;

final class LexwareSyncConfiguration
{
    public const PROJECT_COMPLETION_END_DATE = 'end_date';
    public const PROJECT_COMPLETION_HIDDEN = 'hidden';

    public function __construct(private readonly SystemConfiguration $configuration)
    {
    }

    public function isAutoConvertEnabled(): bool
    {
        return (bool) ($this->configuration->find('lexware_sync.auto_convert_enabled') ?? false);
    }

    public function getTitleRegex(): string
    {
        $value = $this->configuration->find('lexware_sync.title_regex');

        return \is_string($value) ? $value : '';
    }

    public function isReadLinesEnabled(): bool
    {
        return (bool) ($this->configuration->find('lexware_sync.read_lines_enabled') ?? false);
    }

    public function getLineRegex(): string
    {
        $value = $this->configuration->find('lexware_sync.line_regex');

        return \is_string($value) ? $value : '';
    }

    public function getReconcileIntervalMinutes(): int
    {
        $value = $this->configuration->find('lexware_sync.reconcile_interval_minutes');

        return \is_int($value) && $value > 0 ? $value : 30;
    }

    public function getInvoiceTitleRegex(): string
    {
        $value = $this->configuration->find('lexware_sync.invoice_title_regex');

        return \is_string($value) ? $value : '';
    }

    public function getProjectCompletionMode(): string
    {
        $value = $this->configuration->find('lexware_sync.project_completion_mode');

        return $value === self::PROJECT_COMPLETION_HIDDEN ? self::PROJECT_COMPLETION_HIDDEN : self::PROJECT_COMPLETION_END_DATE;
    }
}
