<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\DeliveryNotes;

use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Output\InvoiceAnswer;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Infrastructure\ApiPlatform\Output\InvoiceView;
use App\Module\Invoices\Infrastructure\ApiPlatform\Resource\InvoiceResource;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/** Answers the delivery notes' `InvoiceAnswer` port with the invoice resource this module answers everywhere else. */
final readonly class InvoiceResourceAnswer implements InvoiceAnswer
{
    public function __construct(private InvoiceView $view, private ManageInvoices $manage)
    {
    }

    public function of(Company $company, Uuid $invoiceId): InvoiceResource
    {
        $invoice = $this->manage->get($company, $invoiceId);

        return $this->view->of($company, $invoice, false, $this->manage->roomOnSources($company, $invoice));
    }
}
