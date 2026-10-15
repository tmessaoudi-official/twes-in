<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Module\RecurringExpenses\Domain\RecurringExpenseRepository;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\MembershipRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The worker's pass over a company's recurring expenses: each occurrence due by the company's day becomes a draft
 * copied from its model and dated on its own day, and whoever writes expenses is told it waits for them; nothing is
 * recorded. Drafting an occurrence and moving to the next happen in one transaction, on the schedule locked and read
 * again, so a pass run twice, or two at once, drafts each occurrence once. A schedule whose model can no longer be
 * copied is paused and said so once. Occurrences missed while the worker was down are drafted on the next pass, a
 * bounded number at a time.
 */
final readonly class RunRecurringExpenses
{
    public const string DRAFTED = 'expense.recurring_drafted';
    public const string STOPPED = 'expense.recurring_stopped';
    /** What one pass drafts for one schedule at most: the rest waits for the next pass. */
    public const int MOST_PER_PASS = 24;
    /** Who is told: whoever writes expenses. */
    public const string PERMISSION = 'expense.write';
    private const string AUDIT_DRAFTED = 'recurring_expense.drafted';
    private const string AUDIT_PAUSED = 'recurring_expense.paused';

    public function __construct(
        private ManageRecurringExpenses $manage,
        private RecurringExpenseRepository $recurring,
        private RecurringExpenseDrafts $drafts,
        private RecurringExpenseModels $models,
        private MembershipRepository $memberships,
        private Notifications $notifications,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /** @return int how many drafts were made */
    public function handle(Company $company): int
    {
        $today = $this->manage->today($company);
        $made = 0;
        foreach ($this->recurring->dueOn($company->getId(), $today) as $id) {
            for ($pass = 0; $pass < self::MOST_PER_PASS; ++$pass) {
                $outcome = $this->transactions->run(fn (): ?array => $this->draftNext($company, $id, $today));
                if (null === $outcome) {
                    break;
                }
                $this->tell($company, $outcome['type'], $outcome['payload']);
                if (self::STOPPED === $outcome['type']) {
                    break;
                }
                ++$made;
            }
        }

        return $made;
    }

    /**
     * The next due occurrence of one schedule, drafted; null when nothing is due any more.
     *
     * @return array{type: string, payload: array<string, string>}|null
     */
    private function draftNext(Company $company, Uuid $id, \DateTimeImmutable $today): ?array
    {
        $recurring = $this->recurring->lockedOfIdInCompany($id, $company->getId());
        if (null === $recurring || !$recurring->isDueOn($today)) {
            return null;
        }
        $day = $recurring->getRecurrence()->nextOn ?? $today;
        $model = $this->models->describe($company, $recurring->getModelExpenseId());
        $draftId = null === $model ? null : $this->drafts->draftFrom($company, $recurring->getModelExpenseId(), $recurring->getId(), $day, $recurring->hasVariableAmount());
        $payload = ['recurringExpenseId' => $id->toRfc4122(), 'description' => $model->description ?? '', 'payee' => $model->payee ?? ''];
        if (null === $draftId) {
            $recurring->pause($this->clock->now());
            $this->recurring->save($recurring);
            $this->audit->record(new AuditEntry(ManageRecurringExpenses::ENTITY_TYPE, $id, self::AUDIT_PAUSED, null, ['fields' => ['paused']], $company->getId()));

            return ['type' => self::STOPPED, 'payload' => $payload];
        }
        $recurring->drafted($draftId, $this->clock->now());
        $this->recurring->save($recurring);
        $this->audit->record(new AuditEntry(ManageRecurringExpenses::ENTITY_TYPE, $id, self::AUDIT_DRAFTED, null, ['fields' => ['drafted', 'nextOn', 'lastExpenseId']], $company->getId()));

        return ['type' => self::DRAFTED, 'payload' => [...$payload, 'expenseId' => $draftId->toRfc4122(), 'dueOn' => $day->format('Y-m-d')]];
    }

    /** @param array<string, string> $payload */
    private function tell(Company $company, string $type, array $payload): void
    {
        foreach ($this->memberships->ofCompany($company->getId()) as $membership) {
            if ($membership->getRole()->grants(self::PERMISSION)) {
                $this->notifications->publish(new Notification('user:'.$membership->getUser()->getId()->toRfc4122(), $type, $payload, $company->getId()->toRfc4122()));
            }
        }
    }
}
