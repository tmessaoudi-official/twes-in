<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Pricing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\PriceCalculator;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Shared\RoundingMode;

/**
 * The API tier's consumer of docs/spec/pricing-vectors.json.
 *
 * That file is the single source of truth for arithmetic that exists in three languages — PHP here,
 * TypeScript in the Angular admin, Dart in the Flutter client. Three hand-written implementations of
 * one money formula drift, and the drift shows up as a wrong price rather than a crash, so all three
 * suites read the same fixture. The admin's and the client's equivalents of this file land with those
 * tiers; a tier that does not consume this fixture is a completeness-reviewer P0.
 */
#[CoversClass(PriceCalculator::class)]
#[CoversClass(Rate::class)]
final class PricingVectorsTest extends TestCase
{
    private const string VECTORS = __DIR__ . '/../../../../docs/spec/pricing-vectors.json';

    /**
     * Guards the guard. If the fixture goes missing, is renamed, or loses its cases, every
     * data-provider below would silently supply nothing and this suite would pass while testing
     * nothing at all — the classic vacuous-fixture failure.
     */
    public function testTheFixtureIsPresentAndPopulated(): void
    {
        self::assertFileExists(self::VECTORS, 'The shared pricing vectors are the cross-tier contract.');

        $vectors = self::vectors();

        self::assertSame(1, $vectors['version']);

        // At least one document case must declare the per-line figure, or nothing pins the ORDER in
        // which VAT is rounded — which is the one rounding decision the spec actually rules.
        $pinsRoundingOrder = array_filter(
            $vectors['document_totals'],
            static fn(array $case): bool => isset($case['vat_if_rounded_per_line_which_is_WRONG']),
        );
        self::assertNotEmpty($pinsRoundingOrder, 'No case distinguishes per-line from per-document VAT rounding.');
        self::assertGreaterThanOrEqual(9, \count($vectors['cases']));
        self::assertGreaterThanOrEqual(8, \count($vectors['edit_directions']));
        self::assertGreaterThanOrEqual(3, \count($vectors['document_totals']));
    }

    #[DataProvider('pricingCases')]
    public function testNetVatAndGrossMatchTheSharedVectors(
        string $id,
        string $currency,
        string $cost,
        string $profitRate,
        string $vatRate,
        string $expectedNet,
        string $expectedVat,
        string $expectedGross,
    ): void {
        $calculator = new PriceCalculator();
        $money = Currency::of($currency);

        $net = $calculator->netFromCost(
            Money::of($cost, $money),
            Rate::fromPercentage($profitRate),
            RoundingMode::HalfUp,
        );
        $vat = $calculator->vat($net, Rate::fromPercentage($vatRate), RoundingMode::HalfUp);
        $gross = $net->plus($vat);

        self::assertSame($expectedNet, $net->amount(), "net, case {$id}");
        self::assertSame($expectedVat, $vat->amount(), "vat, case {$id}");
        self::assertSame($expectedGross, $gross->amount(), "gross, case {$id}");
    }

    /** @return iterable<string, array{string, string, string, string, string, string, string, string}> */
    public static function pricingCases(): iterable
    {
        foreach (self::vectors()['cases'] as $case) {
            yield $case['id'] => [
                $case['id'],
                $case['currency'],
                $case['cost'],
                $case['profit_rate'],
                $case['vat_rate'],
                $case['expected']['net'],
                $case['expected']['vat'],
                $case['expected']['gross'],
            ];
        }
    }

    #[DataProvider('editDirectionCases')]
    public function testTheBidirectionalEditsMatchTheSharedVectors(
        string $id,
        string $editedField,
        string $currency,
        string $cost,
        ?string $profitRate,
        ?string $netPrice,
        ?string $expectedNet,
        ?string $expectedRate,
        bool $rateIsExpectedToBeUndefined,
    ): void {
        $calculator = new PriceCalculator();
        $currencyObject = Currency::of($currency);
        $costMoney = Money::of($cost, $currencyObject);

        // Branch on WHICH FIELD THE USER EDITED, because that is the ruled behaviour under test.
        // Branching on which expectation happens to be present instead — as an earlier version did —
        // makes `edited_field` inert: `edit-cost-preserves-rate` and `edit-profit-rate-recomputes-net`
        // then execute identical code, and the ruling that a cost change preserves the rate and moves
        // the price is asserted nowhere. Replacing every edited_field with garbage left the suite green.
        switch ($editedField) {
            case 'profit_rate':
            case 'cost':
                // Both directions recompute the net FROM the rate. That is the whole content of the
                // cost-edit ruling: the rate survives and the price moves.
                self::assertNotNull($profitRate, "case {$id} edits {$editedField} and must supply a profit_rate");
                self::assertNotNull($expectedNet, "case {$id} edits {$editedField} and must expect a net_price");

                $net = $calculator->netFromCost(
                    $costMoney,
                    Rate::fromPercentage($profitRate),
                    RoundingMode::HalfUp,
                );

                self::assertSame($expectedNet, $net->amount(), "net_price, case {$id}");

                break;

            case 'net_price':
                self::assertNotNull($netPrice, "case {$id} edits net_price and must supply one");

                $rate = $calculator->profitRateFromNet(
                    $costMoney,
                    Money::of($netPrice, $currencyObject),
                    RoundingMode::HalfUp,
                );

                if ($rateIsExpectedToBeUndefined) {
                    self::assertNull($rate, "case {$id}: a zero cost leaves the rate undefined, not zero");

                    break;
                }

                self::assertNotNull($rate, "case {$id}");
                self::assertSame($expectedRate, $rate->percentage(), "profit_rate, case {$id}");

                break;

            default:
                self::fail("case {$id} has an unrecognised edited_field \"{$editedField}\"");
        }
    }

    /**
     * @return iterable<string, array{
     *     string, string, string, string, ?string, ?string, ?string, ?string, bool
     * }>
     */
    public static function editDirectionCases(): iterable
    {
        foreach (self::vectors()['edit_directions'] as $case) {
            $expected = $case['expected'];

            yield $case['id'] => [
                $case['id'],
                $case['edited_field'],
                $case['currency'],
                $case['cost'],
                $case['profit_rate'] ?? null,
                $case['net_price'] ?? null,
                $expected['net_price'] ?? null,
                $expected['profit_rate'] ?? null,
                \array_key_exists('profit_rate', $expected) && null === $expected['profit_rate'],
            ];
        }
    }

    /**
     * Document totals end to end: line totals, VAT grouped by rate, and fixed charges.
     *
     * Two things this pins that the formula alone does not. **The rounding ORDER** — VAT is rounded once
     * per rate group on the summed base, not per line and then summed; the two differ by a millime on
     * some inputs and a case in the fixture is built so they diverge. And **that a fixed document charge
     * is not part of any VAT base** — Tunisia's stamp duty is added after VAT, not taxed.
     *
     * @param list<array<string, string>> $lines
     * @param list<array<string, string>> $fixedCharges
     * @param list<array<string, string>> $vatByRate
     */
    #[DataProvider('documentTotalCases')]
    public function testDocumentTotalsMatchTheSharedVectors(
        string $id,
        string $currency,
        array $lines,
        string $expectedSubtotal,
        ?string $singleVatRate,
        array $vatByRate,
        string $expectedVat,
        array $fixedCharges,
        string $expectedTotal,
        ?string $wrongPerLineVat,
    ): void {
        $currencyObject = Currency::of($currency);
        $calculator = new PriceCalculator();

        $subtotal = Money::zero($currencyObject);
        /** @var array<string, Money> $baseByRate */
        $baseByRate = [];

        foreach ($lines as $line) {
            $lineNet = Money::of($line['unit_net'], $currencyObject)
                ->multipliedBy($line['quantity'], RoundingMode::HalfUp);

            self::assertSame($line['line_net'], $lineNet->amount(), "line net, case {$id}");

            $subtotal = $subtotal->plus($lineNet);

            $rate = $line['vat_rate'] ?? $singleVatRate;
            self::assertNotNull($rate, "case {$id}: every line needs a VAT rate");

            // Accumulate the BASE per rate. Rounding happens once, after this loop.
            $baseByRate[$rate] = ($baseByRate[$rate] ?? Money::zero($currencyObject))->plus($lineNet);
        }

        self::assertSame($expectedSubtotal, $subtotal->amount(), "subtotal, case {$id}");

        $vat = Money::zero($currencyObject);

        foreach ($baseByRate as $rate => $base) {
            $groupVat = $calculator->vat($base, Rate::fromPercentage((string) $rate), RoundingMode::HalfUp);
            $vat = $vat->plus($groupVat);

            foreach ($vatByRate as $expectedGroup) {
                if ($expectedGroup['rate'] === (string) $rate) {
                    self::assertSame($expectedGroup['base'], $base->amount(), "base for rate {$rate}, case {$id}");
                    self::assertSame($expectedGroup['vat'], $groupVat->amount(), "vat for rate {$rate}, case {$id}");
                }
            }
        }

        self::assertSame($expectedVat, $vat->amount(), "vat, case {$id}");

        // Where the fixture supplies it, prove the naive order gives a DIFFERENT answer — otherwise the
        // case above would pass under either order and pin nothing.
        if (null !== $wrongPerLineVat) {
            $perLine = Money::zero($currencyObject);

            foreach ($lines as $line) {
                $rate = $line['vat_rate'] ?? $singleVatRate;
                self::assertNotNull($rate);
                $perLine = $perLine->plus($calculator->vat(
                    Money::of($line['line_net'], $currencyObject),
                    Rate::fromPercentage($rate),
                    RoundingMode::HalfUp,
                ));
            }

            self::assertSame($wrongPerLineVat, $perLine->amount(), "per-line VAT, case {$id}");
            self::assertNotSame(
                $expectedVat,
                $perLine->amount(),
                "case {$id} claims the two rounding orders diverge, but they agree — so it pins nothing.",
            );
        }

        $total = $calculator->grossFromNet($subtotal, $vat);

        foreach ($fixedCharges as $charge) {
            $total = $total->plus(Money::of($charge['amount'], $currencyObject));
        }

        self::assertSame($expectedTotal, $total->amount(), "total, case {$id}");
    }

    /**
     * @return iterable<string, array{
     *     string, string, list<array<string, string>>, string, ?string, list<array<string, string>>,
     *     string, list<array<string, string>>, string, ?string
     * }>
     */
    public static function documentTotalCases(): iterable
    {
        foreach (self::vectors()['document_totals'] as $case) {
            yield $case['id'] => [
                $case['id'],
                $case['currency'],
                $case['lines'],
                $case['subtotal_net'],
                $case['vat_rate'] ?? null,
                $case['vat_by_rate'] ?? [],
                $case['vat'],
                $case['fixed_charges'],
                $case['expected']['total'],
                $case['vat_if_rounded_per_line_which_is_WRONG'] ?? null,
            ];
        }
    }

    /** @return array<string, mixed> */
    private static function vectors(): array
    {
        $raw = file_get_contents(self::VECTORS);

        if (false === $raw) {
            self::fail('Could not read ' . self::VECTORS);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
