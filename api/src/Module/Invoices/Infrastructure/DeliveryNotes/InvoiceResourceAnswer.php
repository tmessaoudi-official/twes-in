<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\DeliveryNotes;

use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Output\InvoiceAnswer;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Infrastructure\ApiPlatform\Resource\InvoiceResource;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/** Answers the delivery notes' `InvoiceAnswer` port with the invoice resource this module answers everywhere else. */
final readonly class InvoiceResourceAnswer implements InvoiceAnswer
{
    public function __construct(private InvoiceTotals $totals, private ManageInvoices $manage)
    {
    }

    public function of(Company $company, Uuid $invoiceId): InvoiceResource
    {
        $invoice = $this->manage->get($company, $invoiceId);

        return InvoiceResource::of($invoice, $this->totals->figures($invoice), false, $this->manage->roomOnSources($company, $invoice));
    }
}
