<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use App\Fiscal\Domain\Calculation\ChargeTotal;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Calculation\DocumentTotals;
use App\Fiscal\Domain\Calculation\LineTax;
use App\Fiscal\Domain\Calculation\LineTotals;
use App\Fiscal\Domain\Calculation\TaxTotal;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A document's figures while it is typed (docs/SPEC.md § 7, the live line figures), what every document with lines
 * answers to a preview: each line's, and the document's under the names its own read uses. Amounts are decimal strings
 * at the currency's scale, rates percentages with three decimals. Documents are priced tax-exclusive, so a line's total
 * is its net, less its share of the document discount, with its taxes.
 */
final class DocumentPreview
{
    public const string READ = 'document:preview';

    private const array AMOUNT = ['type' => 'string'];
    private const array RATED = ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['code', 'rate', 'base', 'amount'], 'properties' => ['code' => self::AMOUNT, 'rate' => self::AMOUNT, 'base' => self::AMOUNT, 'amount' => self::AMOUNT]]];

    /** @var list<array{amount: string, discount: string, net: string, documentDiscount: string, taxes: list<array{code: string, base: string, amount: string}>, total: string}> */
    #[ApiProperty(description: 'In the document\'s order: quantity × price, the line discount, the net, its share of the document discount, each of its taxes as this line carries it (its share of the document\'s, so they add up to it), and what the line adds, tax included.', schema: ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['amount', 'discount', 'net', 'documentDiscount', 'taxes', 'total'], 'properties' => [
        'amount' => self::AMOUNT,
        'discount' => self::AMOUNT,
        'net' => self::AMOUNT,
        'documentDiscount' => self::AMOUNT,
        'taxes' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['code', 'base', 'amount'], 'properties' => ['code' => self::AMOUNT, 'base' => self::AMOUNT, 'amount' => self::AMOUNT]]],
        'total' => self::AMOUNT,
    ]]])]
    #[Groups([self::READ])]
    public array $lines = [];

    #[Groups([self::READ])]
    public string $subtotalNet = '0';

    #[Groups([self::READ])]
    public string $documentDiscount = '0';

    #[ApiProperty(description: 'The net after the document discount.')]
    #[Groups([self::READ])]
    public string $totalNet = '0';

    /** @var list<array{code: string, rate: string, base: string, amount: string}> */
    #[ApiProperty(schema: self::RATED)]
    #[Groups([self::READ])]
    public array $taxes = [];

    #[Groups([self::READ])]
    public string $totalTax = '0';

    /** @var list<array{code: string, amount: string}> */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['code', 'amount'], 'properties' => ['code' => self::AMOUNT, 'amount' => self::AMOUNT]]])]
    #[Groups([self::READ])]
    public array $fixedTaxes = [];

    #[Groups([self::READ])]
    public string $total = '0';

    /** @var list<array{code: string, rate: string, base: string, amount: string}> */
    #[ApiProperty(schema: self::RATED)]
    #[Groups([self::READ])]
    public array $withholdings = [];

    #[ApiProperty(description: 'The total less what is withheld at source.')]
    #[Groups([self::READ])]
    public string $netToPay = '0';

    public static function of(DocumentTotals $totals, int $scale): self
    {
        $at = static fn (string $value): string => Decimal::format(Decimal::of($value), $scale);
        $rated = static fn (TaxTotal $each): array => ['code' => $each->code, 'rate' => Decimal::format(Decimal::of($each->rate->percentage()), 3), 'base' => $at($each->base), 'amount' => $at($each->amount)];

        $preview = new self();
        $preview->lines = array_map(static function (LineTotals $line) use ($at, $scale): array {
            $taxes = array_map(static fn (LineTax $tax): array => ['code' => $tax->code, 'base' => $at($tax->base), 'amount' => $at($tax->amount)], $line->taxes);
            $total = Decimal::of($line->net)->sub(Decimal::of($line->documentDiscount))->add(Decimal::sum(array_map(static fn (array $tax) => Decimal::of($tax['amount']), $taxes)));

            return ['amount' => $at($line->amount), 'discount' => $at($line->discount), 'net' => $at($line->net), 'documentDiscount' => $at($line->documentDiscount), 'taxes' => $taxes, 'total' => Decimal::format($total, $scale)];
        }, $totals->lines);
        $preview->subtotalNet = $at($totals->subtotalNet);
        $preview->documentDiscount = $at($totals->documentDiscount);
        $preview->totalNet = $at($totals->netAfterDocumentDiscount);
        $preview->taxes = array_map($rated, $totals->taxes);
        $preview->totalTax = $at($totals->totalTax);
        $preview->fixedTaxes = array_map(static fn (ChargeTotal $charge): array => ['code' => $charge->code, 'amount' => $at($charge->amount)], $totals->fixedCharges);
        $preview->total = $at($totals->total);
        $preview->withholdings = array_map($rated, $totals->withholdings);
        $withheld = Decimal::sum(array_map(static fn (TaxTotal $each) => Decimal::of($each->amount), $totals->withholdings));
        $preview->netToPay = Decimal::format(Decimal::of($totals->total)->sub($withheld), $scale);

        return $preview;
    }
}
