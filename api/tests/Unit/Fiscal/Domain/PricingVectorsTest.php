<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Domain;

use App\Fiscal\Domain\Calculation\AmountTooPrecise;
use App\Fiscal\Domain\Calculation\AuthoredPrice;
use App\Fiscal\Domain\Calculation\DocumentCalculator;
use App\Fiscal\Domain\Calculation\DocumentInput;
use App\Fiscal\Domain\Calculation\DocumentTotals;
use App\Fiscal\Domain\Calculation\LineInput;
use App\Fiscal\Domain\Calculation\LineTax;
use App\Fiscal\Domain\Calculation\ProductPricing;
use App\Fiscal\Domain\Calculation\Rate;
use App\Fiscal\Domain\Calculation\RoundingPoint;
use App\Fiscal\Domain\Calculation\TaxBasis;
use App\Fiscal\Domain\Calculation\TaxInput;
use App\Fiscal\Domain\Calculation\TaxTotal;
use App\Fiscal\Domain\Calculation\UnsupportedTaxCombination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Intl\Currencies;

/**
 * docs/spec/pricing-vectors.json is the pricing arithmetic's source of truth, and this is its consumer: every section,
 * every case. A case missing a field this test reads fails rather than being skipped.
 */
final class PricingVectorsTest extends TestCase
{
    private const string VECTORS = __DIR__.'/../../../../../docs/spec/pricing-vectors.json';

    /** @var array<string, class-string<\Throwable>> */
    private const array ERRORS = [
        'compound_inclusive_unsupported' => UnsupportedTaxCombination::class,
        'amount_scale_exceeds_currency' => AmountTooPrecise::class,
    ];

    public function testEverySectionHasCases(): void
    {
        // A fixture that silently lost its cases would leave every data-provided test below with nothing to run.
        self::assertGreaterThanOrEqual(10, \count(self::section('cases')));
        self::assertGreaterThanOrEqual(8, \count(self::section('edit_directions')));
        self::assertGreaterThanOrEqual(4, \count(self::section('authored_field')));
        self::assertGreaterThanOrEqual(35, \count(self::section('document_totals')));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function productCases(): iterable
    {
        return self::byId('cases');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('productCases')]
    public function testProductPricing(array $case): void
    {
        $scale = self::scaleOf($case);
        $expected = self::object($case, 'expected');

        $net = ProductPricing::net(self::string($case, 'cost'), Rate::fromPercentage(self::string($case, 'profit_rate')), $scale);
        $tax = ProductPricing::tax($net, Rate::fromPercentage(self::string($case, 'vat_rate')), $scale);

        self::assertSame(self::string($expected, 'net'), $net);
        self::assertSame(self::string($expected, 'vat'), $tax);
        self::assertSame(self::string($expected, 'gross'), ProductPricing::gross($net, $tax, $scale));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function editDirections(): iterable
    {
        return self::byId('edit_directions');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('editDirections')]
    public function testEditDirection(array $case): void
    {
        $scale = self::scaleOf($case);
        $expected = self::object($case, 'expected');
        $cost = self::string($case, 'cost');

        switch (self::string($case, 'edited_field')) {
            case 'profit_rate':
            case 'cost':
                $price = AuthoredPrice::byProfitRate($cost, Rate::fromPercentage(self::string($case, 'profit_rate')), $scale);
                self::assertSame(self::string($expected, 'net_price'), $price->netPrice);
                break;
            case 'net_price':
                $price = AuthoredPrice::byNetPrice($cost, self::string($case, 'net_price'), $scale);
                self::assertSame(self::nullableString($expected, 'profit_rate'), $price->profitRate?->percentage());
                break;
            default:
                self::fail('Unknown edited_field.');
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function authoredFields(): iterable
    {
        return self::byId('authored_field');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('authoredFields')]
    public function testAuthoredField(array $case): void
    {
        $scale = self::scaleOf($case);
        $cost = self::string($case, 'cost');
        $expected = self::object($case, 'expected');

        if (AuthoredPrice::BY_NET_PRICE === self::string($case, 'authored_by')) {
            $price = AuthoredPrice::byNetPrice($cost, self::string($case, 'net_price'), $scale);
            self::assertSame(self::nullableString($expected, 'profit_rate'), $price->profitRate?->percentage());
        } else {
            $price = AuthoredPrice::byProfitRate($cost, Rate::fromPercentage(self::string($case, 'profit_rate')), $scale);
            self::assertSame(self::string($expected, 'net_price'), $price->netPrice);
        }

        $after = $price->withCost(self::string($case, 'then_cost_becomes'));
        $expectedAfter = self::object($case, 'expected_after');
        self::assertSame(self::string($expectedAfter, 'net_price'), $after->netPrice);
        self::assertSame(self::string($expectedAfter, 'authored_by'), $after->authoredBy);
        if (\array_key_exists('profit_rate', $expectedAfter)) {
            self::assertSame(self::nullableString($expectedAfter, 'profit_rate'), $after->profitRate?->percentage());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function documents(): iterable
    {
        return self::byId('document_totals');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('documents')]
    public function testDocumentTotals(array $case): void
    {
        $scale = self::scaleOf($case);
        $expected = self::object($case, 'expected');
        $document = \array_key_exists('components', $case) ? self::componentDocument($case, $scale) : self::rateDocument($case, $scale);

        if (\array_key_exists('error', $expected)) {
            $this->expectException(self::ERRORS[self::string($expected, 'error')]);
            (new DocumentCalculator())->calculate($document);

            return;
        }

        $totals = (new DocumentCalculator())->calculate($document);
        self::assertSame(self::string($expected, 'total'), $totals->total, 'total');
        \array_key_exists('components', $case)
            ? self::assertComponents($expected, $totals)
            : self::assertRates($case, $totals);
    }

    /** @param array<string, mixed> $case */
    private static function componentDocument(array $case, int $scale): DocumentInput
    {
        $components = [];
        foreach (self::objects($case, 'components') as $component) {
            $code = self::string($component, 'code');
            $components[$code] = match (self::string($component, 'kind')) {
                'percentage_line' => TaxInput::percentage($code, Rate::fromPercentage(self::string($component, 'rate')), true === ($component['enters_vat_base'] ?? false)),
                'fixed_document' => TaxInput::fixed($code, self::string($component, 'amount')),
                'withholding_total' => TaxInput::withholding($code, Rate::fromPercentage(self::string($component, 'rate')), self::string($component, 'threshold')),
                default => self::fail('Unknown component kind.'),
            };
        }
        /**
         * @param list<mixed> $codes
         *
         * @return list<TaxInput>
         */
        $pick = static function (array $codes) use ($components): array {
            $taxes = [];
            foreach ($codes as $code) {
                self::assertIsString($code, 'a tax is named by its code');
                self::assertArrayHasKey($code, $components, "$code is declared in components");
                $taxes[] = $components[$code];
            }

            return $taxes;
        };

        $lines = [];
        foreach (self::objects($case, 'lines') as $line) {
            $lines[] = new LineInput(
                self::string($line, 'quantity'),
                self::string($line, \array_key_exists('unit_net', $line) ? 'unit_net' : 'unit_gross'),
                self::nullableString($line, 'discount_rate'),
                $pick(self::list($line, 'taxes')),
            );
        }

        return self::documentOf($case, $scale, $lines, $pick(self::list($case, 'document_taxes')));
    }

    /**
     * The earlier cases write a VAT rate per document or per line, and fixed charges; they map onto components
     * named by their canonical rate, so "19" and "19.0" are one tax.
     *
     * @param array<string, mixed> $case
     */
    private static function rateDocument(array $case, int $scale): DocumentInput
    {
        $documentRate = self::nullableString($case, 'vat_rate');
        $priceField = 'inclusive' === self::string($case, 'tax_basis') ? 'unit_gross' : 'unit_net';
        $lines = [];
        foreach (self::objects($case, 'lines') as $line) {
            $rate = self::nullableString($line, 'vat_rate') ?? $documentRate;
            $taxes = null === $rate ? [] : [TaxInput::percentage('VAT '.Rate::fromPercentage($rate)->percentage(), Rate::fromPercentage($rate))];
            $lines[] = new LineInput(
                self::string($line, 'quantity'),
                self::string($line, $priceField),
                self::nullableString($line, 'discount_rate'),
                $taxes,
            );
        }
        $charges = array_map(
            static fn (array $charge) => TaxInput::fixed(self::string($charge, 'label'), self::string($charge, 'amount')),
            self::objects($case, 'fixed_charges'),
        );

        return self::documentOf($case, $scale, $lines, $charges);
    }

    /**
     * @param array<string, mixed> $case
     * @param list<LineInput>      $lines
     * @param list<TaxInput>       $documentTaxes
     */
    private static function documentOf(array $case, int $scale, array $lines, array $documentTaxes): DocumentInput
    {
        return new DocumentInput(
            $scale,
            'credit' === self::string($case, 'document_type'),
            TaxBasis::from(self::string($case, 'tax_basis')),
            RoundingPoint::from(self::string($case, 'vat_rounding_point')),
            $lines,
            self::nullableString($case, 'document_discount'),
            $documentTaxes,
        );
    }

    /** @param array<string, mixed> $case */
    private static function assertRates(array $case, DocumentTotals $totals): void
    {
        self::assertSame(self::string($case, 'subtotal_net'), $totals->subtotalNet, 'subtotal_net');
        self::assertSame(self::string($case, 'vat'), $totals->totalTax, 'vat');
        if (\array_key_exists('subtotal_gross', $case)) {
            self::assertSame(self::string($case, 'subtotal_gross'), $totals->subtotalGross, 'subtotal_gross');
        }
        if (\array_key_exists('net_after_document_discount', $case)) {
            self::assertSame(self::string($case, 'net_after_document_discount'), $totals->netAfterDocumentDiscount, 'net_after_document_discount');
        }
        if (\array_key_exists('document_discount_by_line', $case)) {
            self::assertSame(self::list($case, 'document_discount_by_line'), array_map(static fn ($line) => $line->documentDiscount, $totals->lines), 'document_discount_by_line');
        }
        if (\array_key_exists('vat_by_line', $case)) {
            // Each of these lines carries one rate, so its tax column is that one amount; the order is the lines'.
            self::assertSame(
                self::list($case, 'vat_by_line'),
                array_map(static fn ($line) => implode('+', array_map(static fn (LineTax $tax) => $tax->amount, $line->taxes)), $totals->lines),
                'vat_by_line',
            );
        }
        if (\array_key_exists('vat_by_rate', $case)) {
            $rows = self::objects($case, 'vat_by_rate');
            self::assertCount(\count($rows), $totals->taxes, 'vat_by_rate');
            foreach ($rows as $k => $row) {
                // The fixture writes an output rate canonically, so every tier renders the same string.
                self::assertMatchesRegularExpression('/^-?[0-9]+\.[0-9]{10}$/', self::string($row, 'rate'), 'vat_by_rate[].rate is canonical');
                $tax = $totals->taxes[$k];
                self::assertSame(self::string($row, 'rate'), $tax->rate->percentage(), "vat_by_rate[$k].rate");
                self::assertSame(self::string($row, 'base'), $tax->base, "vat_by_rate[$k].base");
                self::assertSame(self::string($row, 'vat'), $tax->amount, "vat_by_rate[$k].vat");
                if (\array_key_exists('document_discount', $row)) {
                    self::assertSame(self::string($row, 'document_discount'), $tax->documentDiscount, "vat_by_rate[$k].document_discount");
                }
            }
        }
        foreach (self::objects($case, 'lines') as $k => $line) {
            foreach (['line_net' => 'net', 'line_discount' => 'discount', 'line_gross' => 'gross'] as $field => $property) {
                if (\array_key_exists($field, $line)) {
                    self::assertSame(self::string($line, $field), $totals->lines[$k]->{$property}, "lines[$k].$field");
                }
            }
        }
    }

    /** @param array<string, mixed> $expected */
    private static function assertComponents(array $expected, DocumentTotals $totals): void
    {
        self::assertSame(self::string($expected, 'subtotal_net'), $totals->subtotalNet, 'subtotal_net');
        self::assertSame(self::string($expected, 'total_tax'), $totals->totalTax, 'total_tax');
        self::assertSame(self::string($expected, 'amount_due'), $totals->amountDue, 'amount_due');
        self::assertSame(
            self::rows($expected, 'tax_breakdown', ['code', 'base', 'amount']),
            array_map(static fn (TaxTotal $tax) => [$tax->code, $tax->base, $tax->amount], $totals->taxes),
            'tax_breakdown',
        );
        $lineTaxes = [];
        foreach (self::list($expected, 'line_taxes') as $taxes) {
            $lineTaxes[] = self::rows(['taxes' => $taxes], 'taxes', ['code', 'base', 'amount']);
        }
        self::assertSame(
            $lineTaxes,
            array_map(static fn ($line) => array_map(static fn (LineTax $tax) => [$tax->code, $tax->base, $tax->amount], $line->taxes), $totals->lines),
            'line_taxes',
        );
        self::assertSame(
            self::rows($expected, 'fixed_charges', ['code', 'amount']),
            array_map(static fn ($charge) => [$charge->code, $charge->amount], $totals->fixedCharges),
            'fixed_charges',
        );
        self::assertSame(
            self::rows($expected, 'withholding', ['code', 'base', 'amount']),
            array_map(static fn (TaxTotal $tax) => [$tax->code, $tax->base, $tax->amount], $totals->withholdings),
            'withholding',
        );
        if (\array_key_exists('document_discount_by_line', $expected)) {
            self::assertSame(self::list($expected, 'document_discount_by_line'), array_map(static fn ($line) => $line->documentDiscount, $totals->lines), 'document_discount_by_line');
            self::assertSame(self::string($expected, 'net_after_document_discount'), $totals->netAfterDocumentDiscount, 'net_after_document_discount');
        }
    }

    /** @param array<string, mixed> $case */
    private static function scaleOf(array $case): int
    {
        return Currencies::getFractionDigits(self::string($case, 'currency'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    private static function byId(string $section): iterable
    {
        foreach (self::section($section) as $case) {
            yield self::string($case, 'id') => [$case];
        }
    }

    /** @return list<array<string, mixed>> */
    private static function section(string $name): array
    {
        $vectors = json_decode((string) file_get_contents(self::VECTORS), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($vectors);

        return self::objects(self::keyed($vectors), $name);
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string>         $fields
     *
     * @return list<list<string>>
     */
    private static function rows(array $node, string $key, array $fields): array
    {
        return array_map(
            static fn (array $row) => array_map(static fn (string $field) => self::string($row, $field), $fields),
            self::objects($node, $key),
        );
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return list<array<string, mixed>>
     */
    private static function objects(array $node, string $key): array
    {
        return array_map(static function (mixed $item) use ($key): array {
            self::assertIsArray($item, "$key holds objects");

            return self::keyed($item);
        }, self::list($node, $key));
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return list<mixed>
     */
    private static function list(array $node, string $key): array
    {
        self::assertArrayHasKey($key, $node);
        self::assertIsList($node[$key], "$key is a list");

        return $node[$key];
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private static function object(array $node, string $key): array
    {
        self::assertArrayHasKey($key, $node);
        self::assertIsArray($node[$key], "$key is an object");

        return self::keyed($node[$key]);
    }

    /** @param array<string, mixed> $node */
    private static function string(array $node, string $key): string
    {
        self::assertArrayHasKey($key, $node);
        self::assertIsString($node[$key], "$key is a string");

        return $node[$key];
    }

    /** @param array<string, mixed> $node */
    private static function nullableString(array $node, string $key): ?string
    {
        $value = $node[$key] ?? null;
        self::assertTrue(null === $value || \is_string($value), "$key is a string or null");

        return $value;
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<string, mixed>
     */
    private static function keyed(array $node): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
