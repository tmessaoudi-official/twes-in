<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Module\Customers\Domain\Customer;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Module\Invoices\Domain\InvoiceNotDraft;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use Symfony\Component\Uid\Uuid;

/**
 * The draft invoices delivery notes are invoiced on: one drafted from their lines, or their lines added to a draft. A
 * port this module owns, answered by the invoices', which keep the drafts, so neither calls into the other
 * (docs/SPEC.md § 7, audit 2026-10-06 C-4). Both run inside the caller's transaction.
 */
interface InvoiceDrafts
{
    /**
     * @param list<InvoiceLineDetails> $lines
     * @param array<string, mixed>     $origin what the draft was drafted from, as its audit entry says it
     *
     * @throws InvalidInvoice
     */
    public function createFromLines(Company $company, Establishment $establishment, Customer $customer, InvoiceHeader $header, array $lines, array $origin, ?Uuid $actorUserId): Invoice;

    /**
     * @param list<InvoiceLineDetails> $lines
     * @param array<string, mixed>     $origin
     *
     * @throws InvoiceNotFound
     * @throws InvoiceNotDraft
     * @throws InvalidInvoice
     */
    public function appendLines(Company $company, Uuid $id, array $lines, array $origin, ?Uuid $actorUserId): Invoice;
}
