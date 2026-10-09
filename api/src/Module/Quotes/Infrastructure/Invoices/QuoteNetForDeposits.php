<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\Invoices;

use App\Fiscal\Domain\Calculation\InvalidDocument;
use App\Fiscal\Domain\Calculation\UnsupportedTaxCombination;
use App\Module\Invoices\Application\DepositQuotes;
use App\Module\Quotes\Application\QuoteTotals;
use App\Module\Quotes\Domain\QuoteRepository;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * Answers the invoices' `DepositQuotes` port: the quote held, and what it comes to worked out as its own totals are,
 * which is the net its deposits were drawn as shares of.
 */
final readonly class QuoteNetForDeposits implements DepositQuotes
{
    public function __construct(private QuoteRepository $quotes, private QuoteTotals $totals)
    {
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
}
