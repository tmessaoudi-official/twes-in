<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Module\Recurring\Domain\InvalidRecurringInvoice;
use App\Module\Recurring\Domain\RecurringFrequency;
use App\Module\Recurring\Domain\RecurringInvoice;
use App\Module\Recurring\Domain\RecurringInvoiceRepository;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's recurring invoices: made from one of its invoices, which each occurrence copies, revised (how often, the
 * last day, paused or not) and deleted, which leaves the drafts already made as they are. Audited with the names of
 * the fields a revision changed. A day is the company's own.
 */
final readonly class ManageRecurringInvoices
{
    public const string ENTITY_TYPE = 'recurring_invoice';
    public const string CREATED = 'recurring_invoice.created';
    public const string REVISED = 'recurring_invoice.revised';
    public const string DELETED = 'recurring_invoice.deleted';

    public function __construct(
        private RecurringInvoiceRepository $recurring,
        private RecurringModels $models,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    /** @return list<RecurringInvoice> */
    public function list(Company $company): array
    {
        return $this->recurring->ofCompany($company->getId());
    }

    /** @throws RecurringInvoiceNotFound */
    public function get(Company $company, Uuid $id): RecurringInvoice
    {
        return $this->recurring->ofIdInCompany($id, $company->getId()) ?? throw new RecurringInvoiceNotFound();
    }

    /**
     * What the list and a page show of each model, keyed by the model's id.
     *
     * @param list<RecurringInvoice> $recurring
     *
     * @return array<string, RecurringModel>
     */
    public function models(Company $company, array $recurring): array
    {
        $ids = [];
        foreach ($recurring as $one) {
            $ids[$one->getModelInvoiceId()->toRfc4122()] = $one->getModelInvoiceId();
        }

        return $this->models->describeAll($company, array_values($ids));
    }

    /** @throws InvalidRecurringInvoice */
    public function create(Company $company, Uuid $modelInvoiceId, RecurringFrequency $frequency, \DateTimeImmutable $startsOn, ?\DateTimeImmutable $endsOn, ?Uuid $actorUserId): RecurringInvoice
    {
        return $this->transactions->run(function () use ($company, $modelInvoiceId, $frequency, $startsOn, $endsOn, $actorUserId): RecurringInvoice {
            $model = $this->models->describe($company, $modelInvoiceId)
                ?? throw new InvalidRecurringInvoice('modelInvoiceId', 'No invoice of this company has this id.');
            if (!$model->copiable) {
                throw new InvalidRecurringInvoice('modelInvoiceId', 'A credit note or a deposit invoice is not made recurring.');
            }
            $recurring = new RecurringInvoice($company, $modelInvoiceId, $frequency, $startsOn, $endsOn, $this->today($company), $this->clock->now());
            $this->recurring->save($recurring);
            $this->record($company, $recurring->getId(), self::CREATED, ['modelInvoiceId' => $modelInvoiceId->toRfc4122()], $actorUserId);

            return $recurring;
        });
    }

    /**
     * @throws RecurringInvoiceNotFound
     * @throws InvalidRecurringInvoice
     */
    public function revise(Company $company, Uuid $id, RecurringFrequency $frequency, ?\DateTimeImmutable $endsOn, bool $paused, ?Uuid $actorUserId): RecurringInvoice
    {
        return $this->transactions->run(function () use ($company, $id, $frequency, $endsOn, $paused, $actorUserId): RecurringInvoice {
            $recurring = $this->recurring->lockedOfIdInCompany($id, $company->getId()) ?? throw new RecurringInvoiceNotFound();
            $changed = $recurring->revise($frequency, $endsOn, $paused, $this->today($company), $this->clock->now());
            if ([] !== $changed) {
                $this->recurring->save($recurring);
                $this->record($company, $recurring->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
            }

            return $recurring;
        });
    }

    /** @throws RecurringInvoiceNotFound */
    public function delete(Company $company, Uuid $id, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $id, $actorUserId): void {
            $recurring = $this->recurring->lockedOfIdInCompany($id, $company->getId()) ?? throw new RecurringInvoiceNotFound();
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
