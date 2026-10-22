<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Inventory;

use App\Module\Inventory\Application\IssuedInvoices;
use App\Module\Invoices\Domain\Event\InvoiceIssued;
use App\Module\Invoices\Domain\Repository\InvoiceRepository;
use App\Module\Invoices\Domain\ValueObject\InvoiceStatus;
use Symfony\Component\Uid\Uuid;

/** Answers the inventory's `IssuedInvoices` port out of this module, within the company asked about only. */
final readonly class RepositoryIssuedInvoices implements IssuedInvoices
{
    public function __construct(private InvoiceRepository $invoices)
    {
    }

    public function issued(Uuid $companyId, Uuid $invoiceId): ?InvoiceIssued
    {
        $invoice = $this->invoices->ofIdInCompany($invoiceId, $companyId);
        if (null === $invoice || \in_array($invoice->getStatus(), [InvoiceStatus::Draft, InvoiceStatus::Cancelled], true)) {
            return null;
        }

        return $invoice->issuedEvent();
    }
}
