<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application\Invoices;

use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The issued invoices delivery notes deliver, as notes see them. A port this module owns, answered by the invoices', which
 * keep them, so neither calls into the other. A locked read holds the invoice's row until the caller's transaction ends:
 * every write that decides what is left of an invoice holds it, so two notes drafted at once never take more than it.
 */
interface DeliverableInvoices
{
    /** The company's invoice of this id, locked when `$locked`, or null when it has none. */
    public function ofId(Company $company, Uuid $invoiceId, bool $locked): ?InvoiceToDeliver;

    /**
     * The company's invoices holding any of these lines, in id order, locked when `$locked`.
     *
     * @param list<Uuid> $invoiceLineIds
     *
     * @return list<InvoiceToDeliver>
     */
    public function ofLines(Company $company, array $invoiceLineIds, bool $locked): array;
}
