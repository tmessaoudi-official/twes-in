<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\DeliveryNotes\Application\Invoices\DeliverableInvoices;
use App\Module\DeliveryNotes\Application\Invoices\InvoiceLines;
use App\Module\DeliveryNotes\Application\Invoices\InvoiceToDeliver;
use App\Module\DeliveryNotes\Domain\Repository\DeliveryNoteRepository;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/** A company with no issued invoice to deliver, for the unit tests of notes that name none. */
final readonly class NoInvoicesToDeliver implements DeliverableInvoices
{
    public function ofId(Company $company, Uuid $invoiceId, bool $locked): ?InvoiceToDeliver
    {
        return null;
    }

    public function ofLines(Company $company, array $invoiceLineIds, bool $locked): array
    {
        return [];
    }

    public static function lines(DeliveryNoteRepository $notes): InvoiceLines
    {
        return new InvoiceLines(new self(), $notes);
    }
}
