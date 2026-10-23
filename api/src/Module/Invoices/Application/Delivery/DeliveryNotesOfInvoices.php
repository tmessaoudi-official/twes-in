<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Delivery;

use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The delivery notes an issued invoice's goods leave with. A port this module owns, answered by the delivery notes',
 * which keep them, so neither calls into the other. Such a note moves no stock and is never invoiced: the invoice billed
 * the goods and took them out of stock when it was issued.
 */
interface DeliveryNotesOfInvoices
{
    /**
     * What the notes that are not cancelled deliver of each invoice, read once for one company's invoices.
     *
     * @param array<string, list<Uuid>> $lineIdsByInvoice each invoice's line ids, by invoice id
     *
     * @return array<string, InvoiceDeliveries> by invoice id; an invoice no note delivers is absent
     */
    public function ofInvoices(Company $company, array $lineIdsByInvoice): array;
}
