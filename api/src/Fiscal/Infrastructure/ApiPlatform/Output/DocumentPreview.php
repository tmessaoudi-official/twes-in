<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\ApiPlatform\Output;

use ApiPlatform\Metadata\ApiProperty;
use App\Fiscal\Domain\Calculation\ChargeTotal;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Calculation\DocumentTotals;
use App\Fiscal\Domain\Calculation\LineTax;
use App\Fiscal\Domain\Calculation\LineTotals;
use App\Fiscal\Domain\Calculation\SectionSubtotal;
use App\Fiscal\Domain\Calculation\SectionSubtotals;
use App\Fiscal\Domain\Calculation\TaxTotal;
use App\Fiscal\Domain\ValueObject\ChargeLeftOut;
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
    public const array SECTIONS = ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['title', 'firstLine', 'lineCount', 'subtotal'], 'properties' => ['title' => ['type' => 'string'], 'firstLine' => ['type' => 'integer'], 'lineCount' => ['type' => 'integer'], 'subtotal' => ['type' => 'string']]]];
    private const array RATED = ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['code', 'rate', 'base', 'amount'], 'properties' => ['code' => self::AMOUNT, 'rate' => self::AMOUNT, 'base' => self::AMOUNT, 'amount' => self::AMOUNT]]];

    /** @var list<array{amount: string, discount: string, net: string, documentDiscount: string, taxes: list<array{code: string, base: string, amount: string}>, total: string, sentFrom: int|null}> */
    #[ApiProperty(description: 'In the document\'s order: quantity × price, the line discount, the net, its share of the document discount, each of its taxes as this line carries it (its share of the document\'s, so they add up to it), what the line adds, tax included, and where the line was sent from, by its position from 0 among the lines sent: a credit note keeps its lines otherwise than they were sent when it gives deposits back, or leaves them out, and null is a line it adds itself.', schema: ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['amount', 'discount', 'net', 'documentDiscount', 'taxes', 'total', 'sentFrom'], 'properties' => [
        'amount' => self::AMOUNT,
        'discount' => self::AMOUNT,
        'net' => self::AMOUNT,
        'documentDiscount' => self::AMOUNT,
        'taxes' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['code', 'base', 'amount'], 'properties' => ['code' => self::AMOUNT, 'base' => self::AMOUNT, 'amount' => self::AMOUNT]]],
        'total' => self::AMOUNT,
        'sentFrom' => ['type' => ['integer', 'null']],
    ]]])]
    #[Groups([self::READ])]
    public array $lines = [];

    #[Groups([self::READ])]
    public string $subtotalNet = '0';

    #[Groups([self::READ])]
    public string $documentDiscount = '0';

    #[ApiProperty(description: 'What the line discounts and the document discount take off together.')]
    #[Groups([self::READ])]
    public string $savings = '0';

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

    /** @var list<array{title: string, firstLine: int, lineCount: int, subtotal: string}> */
    #[ApiProperty(description: 'The document\'s sections: each opened by the line carrying its title (by position from 0, in `lines`), running to the next one, with what its lines\' nets add up to, excluding tax. Lines before the first title are in none. Only an invoice\'s or a credit note\'s lines carry a title so far: any other document answers none.', schema: self::SECTIONS)]
    #[Groups([self::READ])]
    public array $sections = [];

    /** @var list<array{code: string, reason: string}> */
    #[ApiProperty(description: 'Each fixed charge per document chosen on the document that its figures leave out, with why: `given_back_by_completing_note` (a credit note giving back part of its invoice; the one completing it gives the charge back), `kept` (the country keeps it with the invoice) or `not_charged` (the invoice charged none, or has none left to give back). Only a credit note leaves one out.', schema: ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['code', 'reason'], 'properties' => ['code' => self::AMOUNT, 'reason' => ['type' => 'string', 'enum' => ['given_back_by_completing_note', 'kept', 'not_charged']]]]])]
    #[Groups([self::READ])]
    public array $chargesLeftOut = [];

    /**
     * @param list<string|null>            $titles         the title of the section each line opens, in order; none for a document without sections
     * @param array<string, ChargeLeftOut> $chargesLeftOut why a chosen fixed charge is left out, by its code
     * @param list<int|null>|null          $sentFrom       where each line was sent from, in order; each its own position for a document keeping its lines as sent
     */
    public static function of(DocumentTotals $totals, int $scale, array $titles = [], array $chargesLeftOut = [], ?array $sentFrom = null): self
    {
        $at = static fn (string $value): string => Decimal::format(Decimal::of($value), $scale);
        $rated = static fn (TaxTotal $each): array => ['code' => $each->code, 'rate' => Decimal::format(Decimal::of($each->rate->percentage()), 3), 'base' => $at($each->base), 'amount' => $at($each->amount)];

        $preview = new self();
        $sentFrom ??= array_keys($totals->lines);
        $preview->lines = array_map(static function (LineTotals $line, ?int $sent) use ($at, $scale): array {
            $taxes = array_map(static fn (LineTax $tax): array => ['code' => $tax->code, 'base' => $at($tax->base), 'amount' => $at($tax->amount)], $line->taxes);
            $total = Decimal::of($line->net)->sub(Decimal::of($line->documentDiscount))->add(Decimal::sum(array_map(static fn (array $tax) => Decimal::of($tax['amount']), $taxes)));

            return ['amount' => $at($line->amount), 'discount' => $at($line->discount), 'net' => $at($line->net), 'documentDiscount' => $at($line->documentDiscount), 'taxes' => $taxes, 'total' => Decimal::format($total, $scale), 'sentFrom' => $sent];
        }, $totals->lines, $sentFrom);
        $preview->subtotalNet = $at($totals->subtotalNet);
        $preview->documentDiscount = $at($totals->documentDiscount);
        $preview->savings = $at($totals->savings());
        $preview->totalNet = $at($totals->netAfterDocumentDiscount);
        $preview->taxes = array_map($rated, $totals->taxes);
        $preview->totalTax = $at($totals->totalTax);
        $preview->fixedTaxes = array_map(static fn (ChargeTotal $charge): array => ['code' => $charge->code, 'amount' => $at($charge->amount)], $totals->fixedCharges);
        $preview->total = $at($totals->total);
        $preview->withholdings = array_map($rated, $totals->withholdings);
        $withheld = Decimal::sum(array_map(static fn (TaxTotal $each) => Decimal::of($each->amount), $totals->withholdings));
        $preview->netToPay = Decimal::format(Decimal::of($totals->total)->sub($withheld), $scale);
        $preview->sections = self::sections(SectionSubtotals::of(array_map(static fn (array $line, int $index): array => [$titles[$index] ?? null, $line['net']], $preview->lines, array_keys($preview->lines)), $scale));
        $preview->chargesLeftOut = array_map(static fn (string $code, ChargeLeftOut $why): array => ['code' => $code, 'reason' => $why->value], array_keys($chargesLeftOut), array_values($chargesLeftOut));

        return $preview;
    }

    /**
     * @param list<SectionSubtotal> $sections
     *
     * @return list<array{title: string, firstLine: int, lineCount: int, subtotal: string}>
     */
    public static function sections(array $sections): array
    {
        return array_map(static fn (SectionSubtotal $section): array => ['title' => $section->title, 'firstLine' => $section->firstLine, 'lineCount' => $section->lineCount, 'subtotal' => $section->subtotal], $sections);
    }
}
