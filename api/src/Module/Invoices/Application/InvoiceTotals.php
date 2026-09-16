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
        $corrected = InvoiceType::CreditNote === $invoice->getType() ? $invoice->getCorrectedInvoice() : null;
        $remaining = null === $corrected ? null : $this->remainingWithholdings($corrected, $invoice);
        $documentTaxes = [];
        foreach ($invoice->getDocumentTaxes() as $tax) {
            if (TaxKind::FixedDocument === $tax->getKind()) {
                $documentTaxes[] = TaxInput::fixed($tax->getCode(), $tax->getAmount() ?? '0');
                continue;
            }
            $rate = Rate::fromPercentage($tax->getRate() ?? '0');
            if (null === $remaining) {
                $documentTaxes[] = TaxInput::withholding($tax->getCode(), $rate, $tax->getThreshold() ?? '0');
                continue;
            }
            // A credit note withholds what its invoice withheld, whatever its own total: its own threshold never decides.
            if (isset($remaining[$tax->getCode()])) {
                [$base, $amount] = $remaining[$tax->getCode()];
                $documentTaxes[] = TaxInput::withholdingCompleting($tax->getCode(), $rate, $base, $amount);
            }
        }

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

    /**
     * What is left of each withholding the corrected invoice charged, by code, as base and amount: the invoice's own,
     * less what its other issued credit notes have already taken. The correction completing a base takes what is left
     * of its amount, so the credit notes of a withheld invoice add up to it exactly (docs/SPEC.md § 8, row 25) rather
     * than to what each of them rounded. A base already taken in full leaves nothing, and a correction beyond it
     * rounds its own share as any document does.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function remainingWithholdings(Invoice $corrected, Invoice $correction): array
    {
        $remaining = [];
        foreach ($corrected->getIssuedFigures()->withholdings ?? [] as $held) {
            $remaining[$held['code']] = [Decimal::absolute(Decimal::of($held['base'])), Decimal::absolute(Decimal::of($held['amount']))];
        }
        foreach ($corrected->getCorrections() as $sibling) {
            // This correction's own figures are what is being worked out here; a draft sibling has none, and took nothing.
            if ($sibling->getId()->equals($correction->getId())) {
                continue;
            }
            foreach ($sibling->getIssuedFigures()->withholdings ?? [] as $held) {
                if (isset($remaining[$held['code']])) {
                    $remaining[$held['code']] = [
                        $remaining[$held['code']][0]->sub(Decimal::absolute(Decimal::of($held['base']))),
                        $remaining[$held['code']][1]->sub(Decimal::absolute(Decimal::of($held['amount']))),
                    ];
                }
            }
        }

        $scale = $this->scales->of($corrected->getCompany()->getCurrency());
        $left = [];
        foreach ($remaining as $code => [$base, $amount]) {
            $spent = $base->compare(0) <= 0 || $amount->compare(0) < 0;
            $left[$code] = [Decimal::format($spent ? Decimal::zero() : $base, $scale), Decimal::format($spent ? Decimal::zero() : $amount, $scale)];
        }

        return $left;
    }
}
