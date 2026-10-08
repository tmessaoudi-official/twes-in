<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Module\Recurring\Domain\RecurringInvoiceRepository;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\MembershipRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The worker's pass over a company's recurring invoices: each occurrence due by the company's day becomes a draft
 * copied from its model, and whoever writes invoices is told it waits for them; nothing is issued. Drafting an
 * occurrence and moving to the next happen in one transaction, on the schedule locked and read again, so a pass run
 * twice, or two at once, drafts each occurrence once. A schedule whose model can no longer be copied is paused and
 * said so. Occurrences missed while the worker was down are drafted on the next pass, a bounded number at a time.
 * Drafts are free of a closed period: issuing one dated in it is what `AllocateNumber` refuses.
 */
final readonly class RunRecurringInvoices
{
    public const string DRAFTED = 'invoice.recurring_drafted';
    public const string STOPPED = 'invoice.recurring_stopped';
    /** What one pass drafts for one schedule at most: the rest waits for the next pass. */
    public const int MOST_PER_PASS = 24;
    /** Who is told: whoever writes invoices. */
    private const string PERMISSION = 'invoice.write';
    private const string AUDIT_DRAFTED = 'recurring_invoice.drafted';
    private const string AUDIT_PAUSED = 'recurring_invoice.paused';

    public function __construct(
        private ManageRecurringInvoices $manage,
        private RecurringInvoiceRepository $recurring,
        private RecurringDrafts $drafts,
        private RecurringModels $models,
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
        $dueOn = $recurring->getNextOn()?->format('Y-m-d') ?? $today->format('Y-m-d');
        $model = $this->models->describe($company, $recurring->getModelInvoiceId());
        $draftId = null === $model ? null : $this->drafts->draftFrom($company, $recurring->getModelInvoiceId(), $recurring->getId(), $dueOn);
        $payload = ['recurringInvoiceId' => $id->toRfc4122(), 'customer' => $model->customerName ?? '', 'number' => $model->number ?? ''];
        if (null === $draftId) {
            $recurring->pause($this->clock->now());
            $this->recurring->save($recurring);
            $this->audit->record(new AuditEntry(ManageRecurringInvoices::ENTITY_TYPE, $id, self::AUDIT_PAUSED, null, ['fields' => ['paused']], $company->getId()));

            return ['type' => self::STOPPED, 'payload' => $payload];
        }
        $recurring->drafted($draftId, $this->clock->now());
        $this->recurring->save($recurring);
        $this->audit->record(new AuditEntry(ManageRecurringInvoices::ENTITY_TYPE, $id, self::AUDIT_DRAFTED, null, ['fields' => ['drafted', 'nextOn', 'lastInvoiceId']], $company->getId()));

        return ['type' => self::DRAFTED, 'payload' => [...$payload, 'invoiceId' => $draftId->toRfc4122(), 'dueOn' => $dueOn]];
    }

    /** @param array<string, string> $payload */
    private function tell(Company $company, string $type, array $payload): void
    {
        foreach ($this->memberships->ofCompany($company->getId()) as $membership) {
            if ($membership->getRole()->grants(self::PERMISSION)) {
                $this->notifications->publish(new Notification('user:'.$membership->getUser()->getId()->toRfc4122(), $type, $payload));
            }
        }
    }
}
