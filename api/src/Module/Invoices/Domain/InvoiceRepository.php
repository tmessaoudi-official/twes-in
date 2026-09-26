<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use Symfony\Component\Uid\Uuid;

interface InvoiceRepository
{
    /** @return list<Invoice> one company's invoices and credit notes, the newest first */
    public function ofCompany(Uuid $companyId): array;

    /**
     * One page of a company's invoices and credit notes, searched, narrowed and ordered by the database.
     *
     * @return Page<Invoice>
     */
    public function search(Uuid $companyId, InvoiceSearch $search, PageRequest $page): Page;

    /**
     * How many documents each status of the list would show under the search, its own status left aside, with
     * `overdue` answered by the list's rule on the company's day: the chips of « Factures » (docs/SPEC.md § 7,
     * 2026-09-26). `all` is every status together; overdue documents are counted in their own status too.
     *
     * @return array{all: int, statuses: array<string, int>}
     */
    public function statusCounts(Uuid $companyId, InvoiceSearch $search, \DateTimeImmutable $today): array;

    /** Null for an invoice that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Invoice;

    /** The same, its row held until the transaction this runs in ends; outside a transaction it refuses. */
    public function lockedOfIdInCompany(Uuid $id, Uuid $companyId): ?Invoice;

    /**
     * The company's invoices that are not cancelled with a line invoicing any of these delivery note lines; credit notes
     * never invoice one.
     *
     * @param list<Uuid> $deliveryNoteLineIds
     *
     * @return list<Invoice>
     */
    public function carryingDeliveryNoteLines(Uuid $companyId, array $deliveryNoteLineIds): array;

    /** Whether a document of this type of the company already carries this number. */
    public function numberTaken(Uuid $companyId, InvoiceType $type, string $number): bool;

    public function save(Invoice $invoice): void;
}
