<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Module\Customers\Domain\Model\Customer;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Domain\Exception\InvalidInvoice;
use App\Module\Invoices\Domain\Exception\InvoiceNotDraft;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use Symfony\Component\Uid\Uuid;

/**
 * The draft invoices delivery notes are invoiced on: one drafted from their lines, or their lines added to a draft. A
 * port this module owns, answered by the invoices', which keep the drafts, so neither calls into the other. Each
 * runs inside the caller's transaction.
 */
interface InvoiceDrafts
{
    /**
     * The new draft's id.
     *
     * @param list<InvoiceLineOfNote> $lines
     * @param array<string, mixed>    $origin what the draft was drafted from, as its audit entry says it
     *
     * @throws InvalidInvoice when what a note says no longer makes an invoice line
     */
    public function createFromLines(Company $company, Establishment $establishment, Customer $customer, InvoiceHeaderOfNotes $header, array $lines, array $origin, ?Uuid $actorUserId): Uuid;

    /**
     * @param list<InvoiceLineOfNote> $lines
     * @param array<string, mixed>    $origin
     *
     * @throws InvoiceNotFound
     * @throws InvoiceNotDraft
     * @throws InvalidInvoice  when what a note says no longer makes an invoice line
     */
    public function appendLines(Company $company, Uuid $id, array $lines, array $origin, ?Uuid $actorUserId): void;

    /**
     * The company's invoice of this id as notes see it, read under a lock when `$locked`, or null when it has none. It
     * refuses nothing: whoever asks decides what a non-draft means.
     */
    public function ofId(Company $company, Uuid $id, bool $locked): ?InvoiceToAddTo;
}
