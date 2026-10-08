<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Domain;

use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An invoice drafted again on a schedule (docs/SPEC.md § 7, 2026-09-20): a model invoice of the company, copied into a
 * new draft on each occurrence, which a person then issues or cancels; nothing is ever issued here. The occurrences
 * count from the first day at a frequency, up to an optional last day. Paused, it drafts nothing, and taking it up
 * again starts from the next occurrence to come, never the ones missed meanwhile. The day of the next occurrence is
 * kept beside the count so the worker finds what is due without reading every schedule.
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

    #[ORM\Column(length: 16, enumType: RecurringFrequency::class)]
    private RecurringFrequency $frequency;

    /** The first occurrence, the one counted as 0, whose date a month keeps. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startsOn;

    /** The last day an occurrence may fall on; null when it goes on. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsOn;

    /** The occurrence to draft next, counted from the first. */
    #[ORM\Column]
    private int $nextIndex = 0;

    /** The day of that occurrence; null once past the last day. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextOn;

    /** How many drafts it has made. */
    #[ORM\Column]
    private int $drafted = 0;

    #[ORM\Column]
    private bool $paused = false;

    #[ORM\Column(name: 'last_invoice_id', type: 'uuid', nullable: true)]
    private ?Uuid $lastInvoiceId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @throws InvalidRecurringInvoice */
    public function __construct(Company $company, Uuid $modelInvoiceId, RecurringFrequency $frequency, \DateTimeImmutable $startsOn, ?\DateTimeImmutable $endsOn, \DateTimeImmutable $today, \DateTimeImmutable $now)
    {
        if (self::day($startsOn) < self::day($today)) {
            throw new InvalidRecurringInvoice('startsOn', 'The first draft is made today or later, never on a day already past.');
        }
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->modelInvoiceId = $modelInvoiceId;
        $this->frequency = $frequency;
        $this->startsOn = self::day($startsOn);
        $this->endsOn = self::lastDay($this->startsOn, $endsOn);
        $this->nextOn = $this->occurrenceDay($this->nextIndex);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function isDueOn(\DateTimeImmutable $today): bool
    {
        return !$this->paused && null !== $this->nextOn && self::day($this->nextOn) <= self::day($today);
    }

    /** The occurrence that was due has its draft. */
    public function drafted(Uuid $invoiceId, \DateTimeImmutable $now): void
    {
        ++$this->nextIndex;
        ++$this->drafted;
        $this->lastInvoiceId = $invoiceId;
        $this->nextOn = $this->occurrenceDay($this->nextIndex);
        $this->updatedAt = $now;
    }

    /**
     * Changes how often it drafts, its last day and whether it is paused. A new frequency starts from the occurrence
     * that was next, or from today once it had ended; taking it up again skips what fell due while it was paused.
     *
     * @return list<string> the fields that changed
     *
     * @throws InvalidRecurringInvoice
     */
    public function revise(RecurringFrequency $frequency, ?\DateTimeImmutable $endsOn, bool $paused, \DateTimeImmutable $today, \DateTimeImmutable $now): array
    {
        $today = self::day($today);
        $changed = [];
        if ($frequency !== $this->frequency) {
            $from = null === $this->nextOn ? $today : self::day($this->nextOn);
            $this->startsOn = $from < $today ? $today : $from;
            $this->nextIndex = 0;
            $this->frequency = $frequency;
            $changed[] = 'frequency';
        }
        $endsOn = self::lastDay(self::day($this->startsOn), $endsOn);
        if ($endsOn?->format('Y-m-d') !== $this->endsOn?->format('Y-m-d')) {
            $this->endsOn = $endsOn;
            $changed[] = 'endsOn';
        }
        if ($paused !== $this->paused) {
            $this->paused = $paused;
            $changed[] = 'paused';
        }
        if (!$this->paused) {
            while (null !== ($day = $this->occurrenceDay($this->nextIndex)) && $day < $today) {
                ++$this->nextIndex;
            }
        }
        $this->nextOn = $this->occurrenceDay($this->nextIndex);
        if ([] !== $changed) {
            $this->updatedAt = $now;
        }

        return $changed;
    }

    /** It stops drafting, as when the model can no longer be copied. */
    public function pause(\DateTimeImmutable $now): void
    {
        $this->paused = true;
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

    public function getFrequency(): RecurringFrequency
    {
        return $this->frequency;
    }

    public function getStartsOn(): \DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function getEndsOn(): ?\DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function getNextOn(): ?\DateTimeImmutable
    {
        return $this->nextOn;
    }

    public function getDrafted(): int
    {
        return $this->drafted;
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function getLastInvoiceId(): ?Uuid
    {
        return $this->lastInvoiceId;
    }

    /** The occurrence's day, or null when it falls after the last day. */
    private function occurrenceDay(int $index): ?\DateTimeImmutable
    {
        $day = $this->frequency->occurrence(self::day($this->startsOn), $index);

        return null !== $this->endsOn && $day > self::day($this->endsOn) ? null : $day;
    }

    /** @throws InvalidRecurringInvoice */
    private static function lastDay(\DateTimeImmutable $startsOn, ?\DateTimeImmutable $endsOn): ?\DateTimeImmutable
    {
        if (null === $endsOn) {
            return null;
        }
        $endsOn = self::day($endsOn);
        if ($endsOn < $startsOn) {
            throw new InvalidRecurringInvoice('endsOn', 'The last day comes on or after the first.');
        }

        return $endsOn;
    }

    /** A calendar day, compared as a day: the date as written, at midnight UTC, whatever zone it was read in. */
    private static function day(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
