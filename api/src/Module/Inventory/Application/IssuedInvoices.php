<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Invoices\Domain\Event\InvoiceIssued;
use Symfony\Component\Uid\Uuid;

/**
 * What an issued invoice or credit note said when it was issued, built again so its stock move can be made again after
 * the one its listener ran failed. A port this module owns, answered by the invoices', so neither calls into the other.
 */
interface IssuedInvoices
{
    /** Null for a document of another company, or one never issued, which moves no stock. */
    public function issued(Uuid $companyId, Uuid $invoiceId): ?InvoiceIssued;
}
