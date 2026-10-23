<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain\Repository;

use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteSearch;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use Symfony\Component\Uid\Uuid;

interface DeliveryNoteRepository
{
    /** @return list<DeliveryNote> one company's delivery notes, the newest first */
    public function ofCompany(Uuid $companyId): array;

    /**
     * One page of a company's delivery notes, searched, narrowed and sorted by the database.
     *
     * @return Page<DeliveryNote>
     */
    public function search(Uuid $companyId, DeliveryNoteSearch $search, PageRequest $page): Page;

    /**
     * How many notes each status chip of the list would show under the same words and customer, the status left aside
     * and how many in all.
     *
     * @return array{all: int, statuses: array<string, int>} every status named, in the enum's order
     */
    public function statusCounts(Uuid $companyId, DeliveryNoteSearch $search): array;

    /** Null for a delivery note that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?DeliveryNote;

    /**
     * The company's delivery notes among these ids, read as they are, holding nothing.
     *
     * @param list<Uuid> $ids
     *
     * @return list<DeliveryNote>
     */
    public function ofIdsInCompany(array $ids, Uuid $companyId): array;

    /**
     * The company's delivery notes among these ids, their rows held until the transaction this runs in ends; outside a
     * transaction it refuses.
     *
     * @param list<Uuid> $ids
     *
     * @return list<DeliveryNote>
     */
    public function lockedOfIdsInCompany(array $ids, Uuid $companyId): array;

    /**
     * The same, for the company's delivery notes carrying any of these lines.
     *
     * @param list<Uuid> $lineIds
     *
     * @return list<DeliveryNote>
     */
    public function lockedOfLineIdsInCompany(array $lineIds, Uuid $companyId): array;

    /**
     * How much of each quote line the company's notes take: those not cancelled, drafts included since a draft holds what
     * it took, the note `$except` left out; with `$deliveredOnly` the drafts are left out too, which is what the quote
     * has seen go.
     *
     * @param list<Uuid> $quoteLineIds
     *
     * @return array<string, string> decimal quantities by quote line id; a line no note takes is absent
     */
    public function quoteLineQuantities(Uuid $companyId, array $quoteLineIds, ?Uuid $except = null, bool $deliveredOnly = false): array;

    /**
     * The company's notes that are not cancelled delivering any of these quote lines, the oldest first.
     *
     * @param list<Uuid> $quoteLineIds
     *
     * @return list<DeliveryNote>
     */
    public function delivering(Uuid $companyId, array $quoteLineIds): array;

    /**
     * How much of each issued invoice line the company's notes hand over, counted as `quoteLineQuantities` counts.
     *
     * @param list<Uuid> $invoiceLineIds
     *
     * @return array<string, string> decimal quantities by invoice line id; a line no note takes is absent
     */
    public function invoiceLineQuantities(Uuid $companyId, array $invoiceLineIds, ?Uuid $except = null, bool $deliveredOnly = false): array;

    /**
     * The company's notes that are not cancelled handing over any of these invoice lines, the oldest first.
     *
     * @param list<Uuid> $invoiceLineIds
     *
     * @return list<DeliveryNote>
     */
    public function deliveringInvoiceLines(Uuid $companyId, array $invoiceLineIds): array;

    /**
     * The lines of the company's notes, whatever their state, delivering each of these quote lines.
     *
     * @param list<Uuid> $quoteLineIds
     *
     * @return array<string, list<Uuid>> delivery note line ids by quote line id; a line no note delivers is absent
     */
    public function linesDelivering(Uuid $companyId, array $quoteLineIds): array;

    /** Whether a delivery note of the company already carries this number. */
    public function numberTaken(Uuid $companyId, string $number): bool;

    public function save(DeliveryNote $note): void;
}
