<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Quotes;

use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Quotes\Application\QuoteInvoices;
use App\Module\Quotes\Application\QuoteInvoicingRefused;
use App\Module\Quotes\Domain\Quote;
use App\Module\Quotes\Domain\QuoteLine;
use App\Module\Quotes\Domain\QuoteLineTax;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * Answers the quotes' `QuoteInvoices` port through this module's own use case, audit and checks included: the draft
 * takes the quote's lines with the taxes they name today, its reference, its printed notes and its discount, and the
 * document taxes any new draft of the customer takes.
 */
final readonly class ManagedQuoteInvoices implements QuoteInvoices
{
    public function __construct(private ManageInvoices $manage, private InvoiceRepository $invoices)
    {
    }

    public function draftFrom(Quote $quote, ?Uuid $actorUserId): Uuid
    {
        $header = $quote->getHeader();
        try {
            $lines = array_map(static fn (QuoteLine $line): InvoiceLineDetails => new InvoiceLineDetails(
                $line->getProduct(),
                $line->getDescription(),
                $line->getQuantity(),
                $line->getUnit(),
                $line->getUnitPriceNet(),
                $line->getDiscountRate(),
                array_map(static fn (QuoteLineTax $tax) => $tax->getTaxComponent(), $line->getTaxes()),
            ), $quote->getLines());
            $invoice = $this->manage->createFromLines(
                $quote->getCompany(),
                $quote->getEstablishment(),
                $quote->getCustomer(),
                new InvoiceHeader(customerReference: $header->customerReference, notesPrinted: $header->notesPrinted, discountAmount: $header->discountAmount),
                $lines,
                ['quoteId' => $quote->getId()->toRfc4122()],
                $actorUserId,
            );
        } catch (InvalidInvoice $refused) {
            throw new QuoteInvoicingRefused($refused->field, $refused->getMessage(), $refused);
        }

        return $invoice->getId();
    }

    public function stands(Company $company, Uuid $invoiceId): bool
    {
        $invoice = $this->invoices->ofIdInCompany($invoiceId, $company->getId());

        return null !== $invoice && InvoiceStatus::Cancelled !== $invoice->getStatus();
    }
}
