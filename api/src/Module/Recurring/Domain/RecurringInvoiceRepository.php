<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Domain;

use Symfony\Component\Uid\Uuid;

interface RecurringInvoiceRepository
{
    public function save(RecurringInvoice $recurring): void;

    public function remove(RecurringInvoice $recurring): void;

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?RecurringInvoice;

    /** The same, locked for the rest of the transaction, read again from the database. */
    public function lockedOfIdInCompany(Uuid $id, Uuid $companyId): ?RecurringInvoice;

    /**
     * The company's recurring invoices, the next to draft first and the ended last.
     *
     * @return list<RecurringInvoice>
     */
    public function ofCompany(Uuid $companyId): array;

    /**
     * The ones not paused whose next occurrence falls on or before the day.
     *
     * @return list<Uuid>
     */
    public function dueOn(Uuid $companyId, \DateTimeImmutable $day): array;
}
