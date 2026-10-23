<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use Symfony\Component\Uid\Uuid;

/**
 * What the company's invoices take of delivery note lines. A port this module owns, answered by the invoices', which
 * keep those invoices, so neither calls into the other.
 */
interface InvoicedLines
{
    /**
     * How much of each line the invoices that are not cancelled take, drafts included since a draft holds what it took;
     * with `$issuedOnly` the drafts are left out, which is what decides whether a note has been invoiced.
     *
     * @param list<Uuid> $deliveryNoteLineIds
     *
     * @return array<string, string> decimal quantities by delivery note line id; a line no invoice takes is absent
     */
    public function taken(Uuid $companyId, array $deliveryNoteLineIds, bool $issuedOnly = false): array;

    /**
     * The invoices that are not cancelled carrying any of these lines, drafts included, the oldest first.
     *
     * @param list<Uuid> $deliveryNoteLineIds
     *
     * @return list<CarryingInvoice>
     */
    public function carrying(Uuid $companyId, array $deliveryNoteLineIds): array;

    /**
     * How much discount, as an amount, the invoices that are not cancelled already give on each line, drafts included:
     * what a quote's amount discount has left to share among the parts of it still to invoice.
     *
     * @param list<Uuid> $deliveryNoteLineIds
     *
     * @return array<string, string> decimal amounts by delivery note line id; a line given none is absent
     */
    public function discounts(Uuid $companyId, array $deliveryNoteLineIds): array;
}
