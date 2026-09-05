<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Entity;

use App\Entity\Timesheet;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceTimesheetRepository;

#[ORM\Entity(repositoryClass: TrackedInvoiceTimesheetRepository::class)]
#[ORM\Table(name: 'kimai2_ext_lexware_invoice_timesheet')]
final class TrackedInvoiceTimesheet
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TrackedInvoice::class)]
    #[ORM\JoinColumn(name: 'tracked_invoice_id', nullable: false, onDelete: 'CASCADE')]
    private TrackedInvoice $trackedInvoice;

    #[ORM\ManyToOne(targetEntity: Timesheet::class)]
    #[ORM\JoinColumn(name: 'timesheet_id', nullable: true, onDelete: 'SET NULL')]
    private ?Timesheet $timesheet;

    #[ORM\Column(name: 'timesheet_modified_at_snapshot', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $timesheetModifiedAtSnapshot;

    public function __construct(TrackedInvoice $trackedInvoice, ?Timesheet $timesheet, ?\DateTimeImmutable $timesheetModifiedAtSnapshot)
    {
        $this->trackedInvoice = $trackedInvoice;
        $this->timesheet = $timesheet;
        $this->timesheetModifiedAtSnapshot = $timesheetModifiedAtSnapshot;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrackedInvoice(): TrackedInvoice
    {
        return $this->trackedInvoice;
    }

    public function getTimesheet(): ?Timesheet
    {
        return $this->timesheet;
    }

    public function wasModifiedAfterExport(): bool
    {
        if ($this->timesheet === null || $this->timesheetModifiedAtSnapshot === null) {
            return false;
        }

        $modifiedAt = $this->timesheet->getModifiedAt();

        return $modifiedAt !== null && $modifiedAt > $this->timesheetModifiedAtSnapshot;
    }
}
