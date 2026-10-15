<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Module\RecurringExpenses\Domain\InvalidRecurringExpense;
use App\Module\RecurringExpenses\Domain\RecurringExpense;
use App\Module\RecurringExpenses\Domain\RecurringExpenseRepository;
use App\Shared\Application\Transactions;
use App\Shared\Domain\InvalidSchedule;
use App\Shared\Domain\Schedule;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's recurring expenses: made from one of its expenses, which each occurrence copies, revised (the schedule,
 * the last day, paused or not, whether the amount varies) and deleted, which leaves the drafts already made as they
 * are. Audited with the names of the fields a revision changed. A day is the company's own.
 */
final readonly class ManageRecurringExpenses
{
    public const string ENTITY_TYPE = 'recurring_expense';
    public const string CREATED = 'recurring_expense.created';
    public const string REVISED = 'recurring_expense.revised';
    public const string DELETED = 'recurring_expense.deleted';

    public function __construct(
        private RecurringExpenseRepository $recurring,
        private RecurringExpenseModels $models,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    /** @return list<RecurringExpense> */
    public function list(Company $company): array
    {
        return $this->recurring->ofCompany($company->getId());
    }

    /** @throws RecurringExpenseNotFound */
    public function get(Company $company, Uuid $id): RecurringExpense
    {
        return $this->recurring->ofIdInCompany($id, $company->getId()) ?? throw new RecurringExpenseNotFound();
    }

    /**
     * What the list and a page show of each model, keyed by the model's id.
     *
     * @param list<RecurringExpense> $recurring
     *
     * @return array<string, RecurringExpenseModel>
     */
    public function models(Company $company, array $recurring): array
    {
        $ids = [];
        foreach ($recurring as $one) {
            $ids[$one->getModelExpenseId()->toRfc4122()] = $one->getModelExpenseId();
        }

        return $this->models->describeAll($company, array_values($ids));
    }

    /**
     * @throws InvalidRecurringExpense
     * @throws InvalidSchedule
     */
    public function create(Company $company, Uuid $modelExpenseId, Schedule $schedule, \DateTimeImmutable $startsOn, ?\DateTimeImmutable $endsOn, bool $variableAmount, ?Uuid $actorUserId): RecurringExpense
    {
        return $this->transactions->run(function () use ($company, $modelExpenseId, $schedule, $startsOn, $endsOn, $variableAmount, $actorUserId): RecurringExpense {
            if (null === $this->models->describe($company, $modelExpenseId)) {
                throw new InvalidRecurringExpense('modelExpenseId', 'No expense of this company has this id.', 'unknown_choice');
            }
            $recurring = new RecurringExpense($company, $modelExpenseId, $schedule, $startsOn, $endsOn, $variableAmount, $this->today($company), $this->clock->now());
            $this->recurring->save($recurring);
            $this->record($company, $recurring->getId(), self::CREATED, ['modelExpenseId' => $modelExpenseId->toRfc4122()], $actorUserId);

            return $recurring;
        });
    }

    /**
     * @throws RecurringExpenseNotFound
     * @throws InvalidSchedule
     */
    public function revise(Company $company, Uuid $id, Schedule $schedule, ?\DateTimeImmutable $endsOn, bool $paused, bool $variableAmount, ?Uuid $actorUserId): RecurringExpense
    {
        return $this->transactions->run(function () use ($company, $id, $schedule, $endsOn, $paused, $variableAmount, $actorUserId): RecurringExpense {
            $recurring = $this->recurring->lockedOfIdInCompany($id, $company->getId()) ?? throw new RecurringExpenseNotFound();
            $changed = $recurring->revise($schedule, $endsOn, $paused, $variableAmount, $this->today($company), $this->clock->now());
            if ([] !== $changed) {
                $this->recurring->save($recurring);
                $this->record($company, $recurring->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
            }

            return $recurring;
        });
    }

    /** @throws RecurringExpenseNotFound */
    public function delete(Company $company, Uuid $id, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $id, $actorUserId): void {
            $recurring = $this->recurring->lockedOfIdInCompany($id, $company->getId()) ?? throw new RecurringExpenseNotFound();
            $this->recurring->remove($recurring);
            $this->record($company, $id, self::DELETED, [], $actorUserId);
        });
    }

    /** The company's calendar day now, as a date. */
    public function today(Company $company): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d'), new \DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Uuid $id, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $id, $action, $actorUserId, $changes, $company->getId()));
    }
}
