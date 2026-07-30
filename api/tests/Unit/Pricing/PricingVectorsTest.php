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
use Twes\Domain\Pricing\ProductPricing;
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
#[CoversClass(ProductPricing::class)]
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
        self::assertGreaterThanOrEqual(11, \count($vectors['cases']));
        self::assertGreaterThanOrEqual(8, \count($vectors['edit_directions']));
        self::assertGreaterThanOrEqual(3, \count($vectors['document_totals']));
        self::assertGreaterThanOrEqual(4, \count($vectors['authored_field']));

        // COUNTS ARE NOT COVERAGE, and round 11 proved it on this very file: eleven cases, and not one of
        // them a NEGATIVE tie — while `conventions.rounding` names negative ties as *the* thing that separates
        // a correct implementation from a plausible one, because `Math.round(-0.5)` is -0 in JavaScript. A
        // floor on `count()` cannot notice the absence of a property. So the two properties the conventions
        // actually claim are asserted directly.
        $negative = array_filter(
            $vectors['cases'],
            static fn(array $case): bool => str_starts_with($case['expected']['vat'], '-'),
        );
        self::assertNotEmpty(
            $negative,
            'No case has a negative VAT, so nothing pins half_up as half-AWAY-FROM-ZERO. A TypeScript tier '
            . 'written with Math.round would agree with this fixture on every case in it.',
        );

        // FOUR distinct currency scales. With 0, 2 and 3 only, an implementation hardcoding "at most three
        // decimals" — the natural over-correction to TND's three — passes the whole fixture.
        $scales = array_values(array_unique(array_map(
            static fn(array $case): int => Currency::of($case['currency'])->scale(),
            $vectors['cases'],
        )));
        sort($scales);
        self::assertSame(
            [0, 2, 3, 4],
            $scales,
            'The fixture must exercise a zero-, two-, three- AND four-decimal currency. TND has three, so '
            . 'three is the DEFAULT case here and neither the minimum nor the maximum a tier may assume.',
        );
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

            // THE LOOP BELOW ASSERTS NOTHING IF NOTHING MATCHES, so the match is asserted first. A reviewer
            // respelled a `rate` key from "19" to "19.0" and the whole per-rate breakdown became unassertable:
            // both bases and both VATs could be set to nonsense, or the section emptied entirely, with the suite
            // green — the only signal was the assertion count dropping, which nothing checks. This table is a
            // legally required line on a French and a Tunisian invoice, and this file is named as the reference
            // implementation the Angular and Flutter tiers copy.
            // A MULTI-RATE document must declare its breakdown. Guarding only on "a breakdown was declared" left
            // the sharpest variant open: emptying `vat_by_rate` outright removed every assertion about it and the
            // suite stayed green. A single-rate case carries its rate at the case level and legitimately has no
            // breakdown, so the requirement is conditioned on the shape rather than demanded of every case.
            if (\count($baseByRate) > 1) {
                self::assertNotSame(
                    [],
                    $vatByRate,
                    'A document with more than one VAT rate must declare vat_by_rate — that per-rate table is a '
                    . 'required line on a French and a Tunisian invoice, and without it nothing here is checked.',
                );
            }

            if ([] !== $vatByRate) {
                self::assertSameSize(
                    $vatByRate,
                    $baseByRate,
                    'A declared breakdown must cover every rate present in the lines and no others. A rate string '
                    . 'that matches no line makes its group silently unassertable.',
                );
            }

            foreach ($vatByRate as $declaredGroup) {
                self::assertArrayHasKey(
                    $declaredGroup['rate'],
                    $baseByRate,
                    'No line carries rate "' . $declaredGroup['rate'] . '", so this group would never be checked.',
                );
            }

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

    /**
     * The authored-field rule, which is the one place a rounding decision can silently delete profit.
     *
     * Each case types one field, checks the derived one, then changes the cost and checks what carried
     * forward. Two of them are the exact failures that motivated the design: one millime of profit on a
     * 10,000 TND product, and 0.500 TND on a million.
     *
     * @param array<string, string|null> $expected
     * @param array<string, string|null> $expectedAfter
     */
    #[DataProvider('authoredFieldCases')]
    public function testTheAuthoredFieldIsNeverRecomputed(
        string $id,
        string $currency,
        string $authoredBy,
        string $cost,
        ?string $profitRate,
        ?string $netPrice,
        array $expected,
        string $thenCostBecomes,
        array $expectedAfter,
    ): void {
        $currencyObject = Currency::of($currency);
        $costMoney = Money::of($cost, $currencyObject);

        $pricing = match ($authoredBy) {
            'net_price' => ProductPricing::fromNetPrice(
                $costMoney,
                Money::of($netPrice ?? self::fail("case {$id} needs a net_price"), $currencyObject),
            ),
            'profit_rate' => ProductPricing::fromProfitRate(
                $costMoney,
                Rate::fromPercentage($profitRate ?? self::fail("case {$id} needs a profit_rate")),
            ),
            default => self::fail("case {$id} has an unrecognised authored_by \"{$authoredBy}\""),
        };

        self::assertSame(
            $authoredBy,
            $pricing->authoredBy()->value,
            "case {$id}: authorship must be what the fixture says was typed",
        );

        $this->assertPricingMatches($pricing, $expected, "{$id} (before the cost change)");

        $moved = $pricing->withCost(Money::of($thenCostBecomes, $currencyObject), RoundingMode::HalfUp);

        $this->assertPricingMatches($moved, $expectedAfter, "{$id} (after the cost change)");
    }

    /** @param array<string, string|null> $expected */
    private function assertPricingMatches(ProductPricing $pricing, array $expected, string $context): void
    {
        foreach ($expected as $field => $want) {
            $got = match ($field) {
                'net_price' => $pricing->netPrice(RoundingMode::HalfUp)->amount(),
                'profit_rate' => $pricing->profitRate(RoundingMode::HalfUp)?->percentage(),
                'authored_by' => $pricing->authoredBy()->value,
                default => self::fail("unknown expected field \"{$field}\" in {$context}"),
            };

            self::assertSame($want, $got, "{$field}, {$context}");
        }
    }

    /**
     * @return iterable<string, array{
     *     string, string, string, string, ?string, ?string, array<string, string|null>, string,
     *     array<string, string|null>
     * }>
     */
    public static function authoredFieldCases(): iterable
    {
        foreach (self::vectors()['authored_field'] as $case) {
            yield $case['id'] => [
                $case['id'],
                $case['currency'],
                $case['authored_by'],
                $case['cost'],
                $case['profit_rate'] ?? null,
                $case['net_price'] ?? null,
                $case['expected'],
                $case['then_cost_becomes'],
                $case['expected_after'],
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
