<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Domain\Model;

use App\Shared\Domain\CompanyOwned;
use App\Shared\Domain\InvalidSchedule;
use App\Shared\Domain\Recurrence;
use App\Shared\Domain\Schedule;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An invoice drafted again on a schedule (docs/SPEC.md § 7, 2026-09-20): a model invoice of the company, copied into a
 * new draft on each occurrence, which a person then issues or cancels; nothing is ever issued here. The schedule is the
 * shared one, recurring expenses' too (docs/SPEC.md § 7, 2026-10-15 17:46): any interval, a month or a year on a date,
 * its last day or one of its weekdays.
 */
#[ORM\Entity]
#[ORM\Table(name: 'recurring_invoice')]
#[ORM\Index(name: 'idx_recurring_invoice_company_next', columns: ['company_id', 'next_on'])]
class RecurringInvoice implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    /** The invoice each draft copies. */
    #[ORM\Column(name: 'model_invoice_id', type: 'uuid')]
    private Uuid $modelInvoiceId;

    #[ORM\Embedded(class: Recurrence::class, columnPrefix: false)]
    private Recurrence $recurrence;

    #[ORM\Column(name: 'last_invoice_id', type: 'uuid', nullable: true)]
    private ?Uuid $lastInvoiceId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @throws InvalidSchedule */
    public function __construct(Company $company, Uuid $modelInvoiceId, Schedule $schedule, \DateTimeImmutable $startsOn, ?\DateTimeImmutable $endsOn, \DateTimeImmutable $today, \DateTimeImmutable $now)
    {
        $this->recurrence = new Recurrence($schedule, $startsOn, $endsOn, $today);
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->modelInvoiceId = $modelInvoiceId;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function isDueOn(\DateTimeImmutable $today): bool
    {
        return $this->recurrence->isDueOn($today);
    }

    /** The occurrence that was due has its draft. */
    public function drafted(Uuid $invoiceId, \DateTimeImmutable $now): void
    {
        $this->recurrence->drafted();
        $this->lastInvoiceId = $invoiceId;
        $this->updatedAt = $now;
    }

    /**
     * Changes the schedule, its last day and whether it is paused.
     *
     * @return list<string> the fields that changed
     *
     * @throws InvalidSchedule
     */
    public function revise(Schedule $schedule, ?\DateTimeImmutable $endsOn, bool $paused, \DateTimeImmutable $today, \DateTimeImmutable $now): array
    {
        $changed = $this->recurrence->revise($schedule, $endsOn, $paused, $today);
        if ([] !== $changed) {
            $this->updatedAt = $now;
        }

        return $changed;
    }

    /** It stops drafting, as when the model can no longer be copied. */
    public function pause(\DateTimeImmutable $now): void
    {
        $this->recurrence->pause();
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getModelInvoiceId(): Uuid
    {
        return $this->modelInvoiceId;
    }

    public function getRecurrence(): Recurrence
    {
        return $this->recurrence;
    }

    public function getLastInvoiceId(): ?Uuid
    {
        return $this->lastInvoiceId;
    }
}
