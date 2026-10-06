<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\DeliveryNotes;

use App\Module\Customers\Domain\Customer;
use App\Module\DeliveryNotes\Application\InvoiceDrafts;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use Symfony\Component\Uid\Uuid;

/** Answers the delivery notes' `InvoiceDrafts` port through this module's own use case, audit and checks included. */
final readonly class ManagedInvoiceDrafts implements InvoiceDrafts
{
    public function __construct(private ManageInvoices $manage)
    {
    }

    public function createFromLines(Company $company, Establishment $establishment, Customer $customer, InvoiceHeader $header, array $lines, array $origin, ?Uuid $actorUserId): Invoice
    {
        return $this->manage->createFromLines($company, $establishment, $customer, $header, $lines, $origin, $actorUserId);
    }

    public function appendLines(Company $company, Uuid $id, array $lines, array $origin, ?Uuid $actorUserId): Invoice
    {
        return $this->manage->appendLines($company, $id, $lines, $origin, $actorUserId);
    }
}
