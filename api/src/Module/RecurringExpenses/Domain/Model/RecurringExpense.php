<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Domain\Model;

use App\Shared\Domain\Exception\InvalidSchedule;
use App\Shared\Domain\Model\Recurrence;
use App\Shared\Domain\Model\Schedule;
use App\Shared\Domain\Service\CompanyOwned;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An expense that comes back (docs/SPEC.md § 7, 2026-10-15 17:46): one of the company's expenses copied as a new draft
 * on each occurrence of its schedule, which a person checks and records; nothing is ever recorded here. A variable
 * amount, a bill that differs each time, drafts each copy flagged for its amount to be checked.
 */
#[ORM\Entity]
#[ORM\Table(name: 'recurring_expense')]
#[ORM\Index(name: 'idx_recurring_expense_company_next', columns: ['company_id', 'next_on'])]
class RecurringExpense implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    /** The expense each draft copies. */
    #[ORM\Column(name: 'model_expense_id', type: 'uuid')]
    private Uuid $modelExpenseId;

    #[ORM\Embedded(class: Recurrence::class, columnPrefix: false)]
    private Recurrence $recurrence;

    /** Whether each bill comes to a different amount, so each draft's amount is to be checked. */
    #[ORM\Column]
    private bool $variableAmount;

    #[ORM\Column(name: 'last_expense_id', type: 'uuid', nullable: true)]
    private ?Uuid $lastExpenseId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @throws InvalidSchedule */
    public function __construct(Company $company, Uuid $modelExpenseId, Schedule $schedule, \DateTimeImmutable $startsOn, ?\DateTimeImmutable $endsOn, bool $variableAmount, \DateTimeImmutable $today, \DateTimeImmutable $now)
    {
        $this->recurrence = new Recurrence($schedule, $startsOn, $endsOn, $today);
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->modelExpenseId = $modelExpenseId;
        $this->variableAmount = $variableAmount;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function isDueOn(\DateTimeImmutable $today): bool
    {
        return $this->recurrence->isDueOn($today);
    }

    /** The occurrence that was due has its draft. */
    public function drafted(Uuid $expenseId, \DateTimeImmutable $now): void
    {
        $this->recurrence->drafted();
        $this->lastExpenseId = $expenseId;
        $this->updatedAt = $now;
    }

    /**
     * Changes the schedule, the last day, whether it is paused and whether the amount varies.
     *
     * @return list<string> the fields that changed
     *
     * @throws InvalidSchedule
     */
    public function revise(Schedule $schedule, ?\DateTimeImmutable $endsOn, bool $paused, bool $variableAmount, \DateTimeImmutable $today, \DateTimeImmutable $now): array
    {
        $changed = $this->recurrence->revise($schedule, $endsOn, $paused, $today);
        if ($variableAmount !== $this->variableAmount) {
            $this->variableAmount = $variableAmount;
            $changed[] = 'variableAmount';
        }
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

    public function getModelExpenseId(): Uuid
    {
        return $this->modelExpenseId;
    }

    public function getRecurrence(): Recurrence
    {
        return $this->recurrence;
    }

    public function hasVariableAmount(): bool
    {
        return $this->variableAmount;
    }

    public function getLastExpenseId(): ?Uuid
    {
        return $this->lastExpenseId;
    }
}
