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

    /**
     * What a customer's overdue invoices still have due on a day, how many they are and the earliest due day among
     * them, by the very rule the overdue chip lists with.
     *
     * @return array{amount: string, count: int, oldestDueDate: ?\DateTimeImmutable}
     */
    public function overdueOf(Uuid $companyId, Uuid $customerId, \DateTimeImmutable $today): array;

    /**
     * Every overdue invoice of the company on a day, by the overdue chip's rule, the longest late first: what a reminder
     * run reads, a row each, without loading the documents.
     *
     * @return list<array{invoiceId: Uuid, number: string, customerId: Uuid, customerName: string, dueDate: \DateTimeImmutable, amountDue: string}>
     */
    public function overdueRows(Uuid $companyId, \DateTimeImmutable $today): array;

    /** Null for an invoice that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Invoice;

    /** The company's invoice created last that is not a cancelled draft, which a design preview shows; credit notes aside. */
    public function latestOfCompany(Uuid $companyId): ?Invoice;

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

    /**
     * How much of each delivery note line the company's invoices already take: the sum of the quantities of the lines
     * that name it, on invoices that are not cancelled, drafts included since a draft holds what it took. With
     * `$issuedOnly` the drafts are left out, which is what decides whether a note has been invoiced; `$except` leaves out the
     * invoice being revised, whose own lines are not taken from what it may take.
     *
     * @param list<Uuid> $deliveryNoteLineIds
     *
     * @return array<string, string> decimal quantities by delivery note line id; a line no invoice takes is absent
     */
    public function invoicedQuantities(Uuid $companyId, array $deliveryNoteLineIds, bool $issuedOnly = false, ?Uuid $except = null): array;

    /**
     * The company's invoices that are not cancelled with a line giving this deposit invoice back; a credit note
     * reversing one is not among them.
     *
     * @return list<Invoice>
     */
    public function givingBack(Uuid $companyId, Uuid $depositId): array;

    /**
     * The company's deposit invoices drafted from these quotes, cancelled drafts included, the oldest first.
     *
     * @param list<Uuid> $quoteIds
     *
     * @return list<Invoice>
     */
    public function depositsOfQuotes(Uuid $companyId, array $quoteIds): array;

    /** Whether a document of this type of the company already carries this number. */
    public function numberTaken(Uuid $companyId, InvoiceType $type, string $number): bool;

    public function save(Invoice $invoice): void;
}
