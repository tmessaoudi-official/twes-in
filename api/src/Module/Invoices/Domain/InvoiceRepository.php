<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use Symfony\Component\Uid\Uuid;

interface InvoiceRepository
{
    /** @return list<Invoice> one company's invoices and credit notes, the newest first */
    public function ofCompany(Uuid $companyId): array;

    /** Null for an invoice that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Invoice;

    /** The same, its row held until the transaction this runs in ends; outside a transaction it refuses. */
    public function lockedOfIdInCompany(Uuid $id, Uuid $companyId): ?Invoice;

    /** Whether a document of this type of the company already carries this number. */
    public function numberTaken(Uuid $companyId, InvoiceType $type, string $number): bool;

    public function save(Invoice $invoice): void;
}
