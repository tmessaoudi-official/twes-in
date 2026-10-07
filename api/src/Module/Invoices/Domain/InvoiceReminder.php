<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A stage of the company's reminder calendar that a late invoice reached: the first, second… stage, how late the
 * invoice was that day and when it was recorded. A stage is reached once per invoice; it says it was time to remind
 * the customer, not that anything was sent to them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_reminder')]
#[ORM\UniqueConstraint(name: 'uniq_invoice_reminder_stage', columns: ['invoice_id', 'stage'])]
#[ORM\Index(name: 'idx_invoice_reminder_company', columns: ['company_id'])]
class InvoiceReminder implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(name: 'invoice_id', type: 'uuid')]
    private Uuid $invoiceId;

    /** One for the calendar's first stage. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $stage;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $daysLate;

    /** The company's day the stage was reached. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $reachedOn;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    public function __construct(Company $company, Uuid $invoiceId, int $stage, int $daysLate, \DateTimeImmutable $reachedOn, \DateTimeImmutable $now)
    {
        if ($stage < 1 || $daysLate < 1) {
            throw new \InvalidArgumentException('A reminder stage counts from one, on an invoice at least a day late.');
        }
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->invoiceId = $invoiceId;
        $this->stage = $stage;
        $this->daysLate = $daysLate;
        $this->reachedOn = $reachedOn;
        $this->recordedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getInvoiceId(): Uuid
    {
        return $this->invoiceId;
    }

    public function getStage(): int
    {
        return $this->stage;
    }

    public function getDaysLate(): int
    {
        return $this->daysLate;
    }

    public function getReachedOn(): \DateTimeImmutable
    {
        return $this->reachedOn;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
