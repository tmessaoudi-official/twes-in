<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Fiscal\Domain\Calculation\ChargeTotal;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Calculation\DocumentTotals;
use App\Fiscal\Domain\Calculation\LineTax;
use App\Fiscal\Domain\Calculation\LineTotals;
use App\Fiscal\Domain\Calculation\TaxTotal;

/**
 * Every figure an invoice or a credit note prints (docs/SPEC.md § 7, 2026-09-14): a draft's worked out on every read,
 * an issued document's as issuing wrote them. Amounts are decimal strings, rates percentages with three decimals.
 */
final readonly class InvoiceFigures
{
    /**
     * @param list<array{code: string, rate: string, base: string, amount: string}> $taxes        each line tax over the lines carrying it
     * @param list<array{code: string, amount: string}>                             $fixedTaxes   the fixed charges, outside every tax base
     * @param list<array{code: string, rate: string, base: string, amount: string}> $withholdings the withholdings whose threshold the document reaches
     * @param list<array{net: string, tax: string, gross: string}>                  $lines        in the document's order
     */
    public function __construct(
        public string $subtotalNet,
        public string $documentDiscount,
        public string $totalNet,
        public array $taxes,
        public string $totalTax,
        public array $fixedTaxes,
        public string $total,
        public array $withholdings,
        public string $withholdingAmount,
        public string $amountDue,
        public array $lines,
        public string $amountPaid = '0.000',
        public string $amountCredited = '0.000',
    ) {
    }

    /** What the calculator worked out, nothing paid or credited yet, at the currency's scale. */
    public static function of(DocumentTotals $totals, int $scale): self
    {
        $tax = static fn (TaxTotal $each): array => ['code' => $each->code, 'rate' => Decimal::format(Decimal::of($each->rate->percentage()), 3), 'base' => $each->base, 'amount' => $each->amount];

        return new self(
            $totals->subtotalNet,
            $totals->documentDiscount,
            $totals->netAfterDocumentDiscount,
            array_map($tax, $totals->taxes),
            $totals->totalTax,
            array_map(static fn (ChargeTotal $charge): array => ['code' => $charge->code, 'amount' => $charge->amount], $totals->fixedCharges),
            $totals->total,
            array_map($tax, $totals->withholdings),
            Decimal::format(Decimal::sum(array_map(static fn (TaxTotal $each) => Decimal::of($each->amount), $totals->withholdings)), $scale),
            $totals->amountDue,
            array_map(static function (LineTotals $line) use ($scale): array {
                $lineTax = Decimal::round(Decimal::sum(array_map(static fn (LineTax $each) => Decimal::of($each->amount), $line->taxes)), $scale);

                return ['net' => $line->net, 'tax' => Decimal::format($lineTax, $scale), 'gross' => Decimal::format(Decimal::sum([Decimal::of($line->net), $lineTax]), $scale)];
            }, $totals->lines),
        )->atScale($scale);
    }

    /** The same figures written with the currency's number of decimals, as a stored column may hold more. */
    public function atScale(int $scale): self
    {
        $amount = static fn (string $value): string => Decimal::format(Decimal::of($value), $scale);

        return new self(
            $amount($this->subtotalNet),
            $amount($this->documentDiscount),
            $amount($this->totalNet),
            self::rated($this->taxes, $scale),
            $amount($this->totalTax),
            array_map(static fn (array $charge): array => ['code' => $charge['code'], 'amount' => $amount($charge['amount'])], $this->fixedTaxes),
            $amount($this->total),
            self::rated($this->withholdings, $scale),
            $amount($this->withholdingAmount),
            $amount($this->amountDue),
            array_map(static fn (array $line): array => ['net' => $amount($line['net']), 'tax' => $amount($line['tax']), 'gross' => $amount($line['gross'])], $this->lines),
            $amount($this->amountPaid),
            $amount($this->amountCredited),
        );
    }

    /**
     * @param list<array{code: string, rate: string, base: string, amount: string}> $taxes
     *
     * @return list<array{code: string, rate: string, base: string, amount: string}>
     */
    private static function rated(array $taxes, int $scale): array
    {
        return array_map(static fn (array $each): array => [
            'code' => $each['code'],
            'rate' => $each['rate'],
            'base' => Decimal::format(Decimal::of($each['base']), $scale),
            'amount' => Decimal::format(Decimal::of($each['amount']), $scale),
        ], $taxes);
    }
}
