<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Calculation\DocumentCalculator;
use App\Fiscal\Domain\Calculation\DocumentInput;
use App\Fiscal\Domain\Calculation\DocumentTotals;
use App\Fiscal\Domain\Calculation\InvalidDocument;
use App\Fiscal\Domain\Calculation\LineInput;
use App\Fiscal\Domain\Calculation\Rate;
use App\Fiscal\Domain\Calculation\TaxBasis;
use App\Fiscal\Domain\Calculation\TaxInput;
use App\Fiscal\Domain\Calculation\UnsupportedTaxCombination;
use App\Module\Quotes\Domain\InvalidQuote;
use App\Module\Quotes\Domain\Quote;
use App\Module\Quotes\Domain\QuoteLine;
use App\Module\Quotes\Domain\QuoteLineTax;

/**
 * What a quote comes to, worked out by the calculator every document shares, the way the invoice it becomes will:
 * prices net of tax, each line's discount, the document discount spread over the lines, the line taxes at the rates the
 * quote keeps, rounded where the company's fiscal preset rounds VAT and at its currency's scale. A quote carries no
 * document tax: the stamp and the withholding belong to the invoice that is issued (docs/SPEC.md § 7, 2026-10-07 10:21).
 */
final readonly class QuoteTotals
{
    public function __construct(private FiscalPresets $presets, private CurrencyScales $scales)
    {
    }

    /**
     * The totals, or what refuses them named: a line's discount amount finer than the currency or above the line on
     * `lines[i].discountAmount`, a document discount finer than the currency or above the lines' net on
     * `discountAmount`, anything else the calculator refuses on `lines`.
     *
     * @throws InvalidQuote
     */
    public function checked(Quote $quote): DocumentTotals
    {
        $this->checkLineDiscounts($quote);
        $discount = $quote->getHeader()->discountAmount;
        if (null !== $discount) {
            $scale = $this->scales->of($quote->getCompany()->getCurrency());
            if (0 !== Decimal::round(Decimal::of($discount), $scale)->compare(Decimal::of($discount))) {
                throw new InvalidQuote('discountAmount', \sprintf('The currency %s has %d decimals.', $quote->getCompany()->getCurrency(), $scale));
            }
        }

        try {
            if (null !== $discount) {
                $lines = Decimal::of($this->calculate($quote, null)->subtotalNet);
                if (Decimal::of($discount)->compare($lines) > 0) {
                    throw new InvalidQuote('discountAmount', \sprintf('A document discount is at most what the lines come to, %s.', Decimal::format($lines, 3)));
                }
            }

            return $this->of($quote);
        } catch (InvalidDocument|UnsupportedTaxCombination $refused) {
            throw new InvalidQuote('lines', $refused->getMessage());
        }
    }

    /** @throws InvalidQuote */
    private function checkLineDiscounts(Quote $quote): void
    {
        $scale = $this->scales->of($quote->getCompany()->getCurrency());
        foreach ($quote->getLines() as $i => $line) {
            $discount = $line->getDiscountAmount();
            if (null === $discount) {
                continue;
            }
            $field = \sprintf('lines[%d].discountAmount', $i);
            if (0 !== Decimal::round(Decimal::of($discount), $scale)->compare(Decimal::of($discount))) {
                throw new InvalidQuote($field, \sprintf('The currency %s has %d decimals.', $quote->getCompany()->getCurrency(), $scale));
            }
            $amount = Decimal::round(Decimal::of($line->getQuantity())->mul(Decimal::of($line->getUnitPriceNet()), Decimal::WORKING_SCALE), $scale);
            if (Decimal::of($discount)->compare($amount) > 0) {
                throw new InvalidQuote($field, \sprintf('A line\'s discount is at most what the line comes to, %s.', Decimal::format($amount, $scale)));
            }
        }
    }

    /**
     * @throws InvalidDocument           when the quote cannot be totalled
     * @throws UnsupportedTaxCombination when the lines combine taxes the calculator does not
     */
    public function of(Quote $quote): DocumentTotals
    {
        return $this->calculate($quote, $quote->getHeader()->discountAmount);
    }

    private function calculate(Quote $quote, ?string $documentDiscount): DocumentTotals
    {
        $company = $quote->getCompany();
        $lines = array_map(static fn (QuoteLine $line): LineInput => new LineInput(
            $line->getQuantity(),
            $line->getUnitPriceNet(),
            $line->getDiscountRate(),
            array_map(static fn (QuoteLineTax $tax): TaxInput => TaxInput::percentage($tax->getCode(), Rate::fromPercentage($tax->getRate()), $tax->entersVatBase()), $line->getTaxes()),
            discountAmount: $line->getDiscountAmount(),
        ), $quote->getLines());

        return new DocumentCalculator()->calculate(new DocumentInput(
            $this->scales->of($company->getCurrency()),
            false,
            TaxBasis::Exclusive,
            $this->presets->get($company->getFiscalPreset())->vatRoundingPoint,
            $lines,
            $documentDiscount,
        ));
    }
}
