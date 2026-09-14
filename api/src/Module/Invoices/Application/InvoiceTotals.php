<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

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
use App\Fiscal\Domain\TaxKind;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceFigures;
use App\Module\Invoices\Domain\InvoiceLine;
use App\Module\Invoices\Domain\InvoiceLineTax;
use App\Module\Invoices\Domain\InvoiceTax;
use App\Module\Invoices\Domain\InvoiceType;

/**
 * What an invoice or a credit note comes to, worked out by the calculator every document shares: prices net of tax,
 * each line's discount, the document discount spread over the lines, the line taxes at the rates the document keeps,
 * then its fixed charges and its withholdings, rounded where the company's fiscal preset rounds VAT and at its
 * currency's scale. A credit note's figures are negative.
 */
final readonly class InvoiceTotals
{
    public function __construct(private FiscalPresets $presets, private CurrencyScales $scales)
    {
    }

    /**
     * The totals, or what refuses them named: a document discount finer than the currency or above the lines' net on
     * `discountAmount`, anything else the calculator refuses on `lines`.
     *
     * @throws InvalidInvoice
     */
    public function checked(Invoice $invoice): DocumentTotals
    {
        $discount = $invoice->getHeader()->discountAmount;
        if (null !== $discount) {
            $scale = $this->scales->of($invoice->getCompany()->getCurrency());
            if (0 !== Decimal::round(Decimal::of($discount), $scale)->compare(Decimal::of($discount))) {
                throw new InvalidInvoice('discountAmount', \sprintf('The currency %s has %d decimals.', $invoice->getCompany()->getCurrency(), $scale));
            }
        }

        try {
            if (null !== $discount) {
                $lines = Decimal::absolute(Decimal::of($this->calculate($invoice, null)->subtotalNet));
                if (Decimal::of($discount)->compare($lines) > 0) {
                    throw new InvalidInvoice('discountAmount', \sprintf('A document discount is at most what the lines come to, %s.', Decimal::format($lines, 3)));
                }
            }

            return $this->of($invoice);
        } catch (InvalidDocument|UnsupportedTaxCombination $refused) {
            throw new InvalidInvoice('lines', $refused->getMessage());
        }
    }

    /**
     * What a document prints: a draft's figures worked out now, an issued document's as issuing wrote them, never
     * recomputed (docs/SPEC.md § 7, 2026-09-14). Amounts at the currency's scale.
     *
     * @throws InvalidDocument           when a draft cannot be totalled
     * @throws UnsupportedTaxCombination when a draft's lines combine taxes the calculator does not
     */
    public function figures(Invoice $invoice): InvoiceFigures
    {
        $scale = $this->scales->of($invoice->getCompany()->getCurrency());

        return $invoice->getIssuedFigures()?->atScale($scale) ?? InvoiceFigures::of($this->of($invoice), $scale);
    }

    /**
     * The figures issuing fixes, checked as a draft's are.
     *
     * @throws InvalidInvoice
     */
    public function issued(Invoice $invoice): InvoiceFigures
    {
        return InvoiceFigures::of($this->checked($invoice), $this->scales->of($invoice->getCompany()->getCurrency()));
    }

    /**
     * @throws InvalidDocument           when the document cannot be totalled
     * @throws UnsupportedTaxCombination when the lines combine taxes the calculator does not
     */
    public function of(Invoice $invoice): DocumentTotals
    {
        return $this->calculate($invoice, $invoice->getHeader()->discountAmount);
    }

    private function calculate(Invoice $invoice, ?string $documentDiscount): DocumentTotals
    {
        $company = $invoice->getCompany();
        $lines = array_map(static fn (InvoiceLine $line): LineInput => new LineInput(
            $line->getQuantity(),
            $line->getUnitPriceNet(),
            $line->getDiscountRate(),
            array_map(static fn (InvoiceLineTax $tax): TaxInput => TaxInput::percentage($tax->getCode(), Rate::fromPercentage($tax->getRate()), $tax->entersVatBase()), $line->getTaxes()),
        ), $invoice->getLines());
        $documentTaxes = array_map(static fn (InvoiceTax $tax): TaxInput => TaxKind::FixedDocument === $tax->getKind()
            ? TaxInput::fixed($tax->getCode(), $tax->getAmount() ?? '0')
            : TaxInput::withholding($tax->getCode(), Rate::fromPercentage($tax->getRate() ?? '0'), $tax->getThreshold() ?? '0'), $invoice->getDocumentTaxes());

        return new DocumentCalculator()->calculate(new DocumentInput(
            $this->scales->of($company->getCurrency()),
            InvoiceType::CreditNote === $invoice->getType(),
            TaxBasis::Exclusive,
            $this->presets->get($company->getFiscalPreset())->vatRoundingPoint,
            $lines,
            $documentDiscount,
            $documentTaxes,
        ));
    }
}
