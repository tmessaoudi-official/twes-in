<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\Invoices;

use App\Fiscal\Domain\Calculation\InvalidDocument;
use App\Fiscal\Domain\Calculation\UnsupportedTaxCombination;
use App\Module\Invoices\Application\Deposit\DepositQuotes;
use App\Module\Quotes\Application\Delivery\QuoteDeliveryNotes;
use App\Module\Quotes\Application\QuoteInvoices;
use App\Module\Quotes\Application\QuoteTotals;
use App\Module\Quotes\Domain\Model\QuoteLine;
use App\Module\Quotes\Domain\Repository\QuoteRepository;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * Answers the invoices' `DepositQuotes` port: the quote held, and what it comes to worked out as its own totals are,
 * which is the net its deposits were drawn as shares of; and whether it was billed otherwise, as drafting a deposit asks.
 */
final readonly class QuoteNetForDeposits implements DepositQuotes
{
    public function __construct(
        private QuoteRepository $quotes,
        private QuoteTotals $totals,
        private QuoteInvoices $invoices,
        private QuoteDeliveryNotes $deliveries,
    ) {
    }

    public function heldNet(Company $company, Uuid $quoteId): ?string
    {
        $quote = $this->quotes->lockedOfIdInCompany($quoteId, $company->getId());
        if (null === $quote) {
            return null;
        }
        try {
            return $this->totals->of($quote)->netAfterDocumentDiscount;
        } catch (InvalidDocument|UnsupportedTaxCombination $refused) {
            // A quote is totalled before it is sent, and an accepted one is no longer edited.
            throw new \LogicException('A quote that took a deposit is totalled.', 0, $refused);
        }
    }

    public function billedOtherwise(Company $company, Uuid $quoteId): ?string
    {
        $quote = $this->quotes->ofIdInCompany($quoteId, $company->getId());
        if (null === $quote) {
            return null;
        }
        $number = $quote->getNumber() ?? $quoteId->toRfc4122();
        $invoiceId = $quote->getInvoiceId();
        if (null !== $invoiceId && $this->invoices->stands($company, $invoiceId)) {
            return \sprintf('The quote %s is already on an invoice drafted from it as a whole: its deposits come before it.', $number);
        }
        $key = $quoteId->toRfc4122();
        $lines = array_map(static fn (QuoteLine $line): Uuid => $line->getId(), $quote->getLines());
        if ([] !== ($this->deliveries->ofQuotes($company, [$key => $lines])[$key]->notes ?? [])) {
            return \sprintf('The quote %s is delivered by delivery notes that are not cancelled, whose invoices give no deposit back.', $number);
        }

        return null;
    }
}
