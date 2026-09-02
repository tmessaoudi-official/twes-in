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
use Twes\Domain\Document\DocumentCalculator;
use Twes\Domain\Document\DocumentLine;
use Twes\Domain\Document\FixedCharge;
use Twes\Domain\Document\VatRoundingPoint;
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
            static fn(array $case): bool => isset($case['vat_if_per_line']) || isset($case['vat_if_per_rate_group']),
        );
        self::assertNotEmpty($pinsRoundingOrder, 'No case distinguishes per-line from per-document VAT rounding.');
        // TEN, not eleven: the negative-tie case was REMOVED by developer ruling (2026-07-30), not lost.
        // Lowered deliberately and in the same change as the removal, because a floor left at 11 would have
        // failed loudly and invited somebody to re-add the very shape the ruling refuses.
        self::assertGreaterThanOrEqual(10, \count($vectors['cases']));
        self::assertGreaterThanOrEqual(8, \count($vectors['edit_directions']));
        self::assertGreaterThanOrEqual(3, \count($vectors['document_totals']));
        self::assertGreaterThanOrEqual(4, \count($vectors['authored_field']));

        // COUNTS ARE NOT COVERAGE, and this file has now proved it twice. Round 11 found eleven cases and
        // not one negative tie, while `conventions.rounding` names negative ties as *the* discriminator. Round
        // 12 then proved the guard added for it was itself vacuous: it filtered on `str_starts_with($vat, '-')`
        // — a test for a negative SIGN, not a negative TIE — and both a sign-preserving mutation and a
        // tie-destroying one survived it. A sign test cannot notice the absence of a tie, exactly as a
        // count() floor cannot notice the absence of a property.
        //
        // THE NEGATIVE-TIE CASE IS GONE, by developer ruling (2026-07-30), not by oversight: reaching a
        // negative tie from a product requires a rate below -100%, which derives a negative selling price, and
        // `ProductPricing` now refuses that because a negative gross is a CREDIT NOTE. So the discriminator is
        // Wave 2's obligation, stated in `conventions.rounding` and in Wave 2's scope. Asserted here so the
        // obligation cannot be quietly dropped: the fixture must SAY it is owed for as long as it is owed.
        self::assertStringContainsString(
            'OWED TO WAVE 2',
            $vectors['conventions']['rounding'],
            'The negative-tie gap must stay named in the fixture until Wave 2 closes it. If Wave 2 has landed '
            . 'a Credit case with a negative tie, delete this assertion in the same change.',
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

        // AND THE SAME REQUIREMENT ON `document_totals`, which had it made in prose one section up and enforced
        // nowhere. Round 14 found every document case was TND, so substituting the literal 3 for the currency's
        // own scale inside `DocumentCalculator` survived the ENTIRE suite — twice, once on the total and once on
        // the subtotal accumulator. The argument above ("an implementation hardcoding at most three decimals
        // passes the whole fixture") was true of this section and untested in it, which is the exemption-inside-
        // a-cross-check shape § Gotchas records.
        $documentScales = array_values(array_unique(array_map(
            static fn(array $case): int => Currency::of($case['currency'])->scale(),
            $vectors['document_totals'],
        )));
        sort($documentScales);
        self::assertSame(
            [0, 2, 3, 4],
            $documentScales,
            'A whole DOCUMENT must be totalled in a zero-, two-, three- and four-decimal currency. Totalling '
            . 'is where the scale is used most (line nets, per-rate VAT, the sum), so TND-only coverage here '
            . 'leaves the kernel free to assume three decimals.',
        );

        // AND THE EXPECTED RATES ARE CANONICAL, which round 15 found nothing pinned. `conventions.rates` mandates
        // "exactly 10 decimal places" and `vat_by_rate[].rate` was written as `19`, so a tier emitting `19` and a
        // tier emitting `19.0000000000` for the same required table BOTH passed — because each consumer
        // normalises the declared value through `Rate::fromPercentage()->percentage()` before comparing, which
        // makes the fixture's own spelling unobservable. Asserted on the RAW string for exactly that reason.
        foreach ($vectors['document_totals'] as $case) {
            foreach ($case['vat_by_rate'] ?? [] as $group) {
                self::assertMatchesRegularExpression(
                    '/^-?\d+\.\d{10}$/',
                    (string) $group['rate'],
                    \sprintf(
                        'Case "%s" declares a VAT-breakdown rate of "%s". conventions.rates requires exactly 10 '
                        . 'decimal places, and this section is EXPECTED OUTPUT — two tiers rendering the same '
                        . 'rate differently must not both pass.',
                        (string) $case['id'],
                        (string) $group['rate'],
                    ),
                );
            }
        }
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
     * Two things this pins that the formula alone does not. **The rounding ORDER** — which is a per-case
     * SETTING, not a single behaviour: every case declares `vat_rounding_point`, and this method runs it under
     * the point it declares. The two orders differ by a millime on some inputs, and the fixture carries a
     * diverging pair — one case per point — so neither is pinned only by the other's absence. This docblock
     * asserted `per_rate_group` as *the* behaviour until round 6, which is what R5K-3 was about and what the
     * `PerRateGroup` literal in this very method had encoded. And **that a fixed document charge is not part
     * of any VAT base** — Tunisia's stamp duty is added after VAT, not taxed.
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
        ?string $divergentVat,
        string $vatRoundingPoint,
    ): void {
        // DELEGATED TO THE KERNEL. This method used to open-code the whole composition — line net, subtotal,
        // base grouped by rate, one vat() per group, the divergence arm, gross plus charges — which made it a
        // SECOND implementation of the thing `CLAUDE.md` § Architecture says must have exactly one
        // ("Tax, discounts and rounding order are one implementation, never two"), driven from the same
        // fixture section with nothing asserting the two agreed.
        //
        // AND THEY DID NOT AGREE. Round 13 reproduced it: this copy grouped on the RAW FIXTURE STRING
        // (`$line['vat_rate'] ?? $singleVatRate`), so rates spelled `"19"` and `"19.0"` became two groups and
        // 0.004 — while `DocumentCalculator` groups on the canonical `Rate::percentage()` and correctly gives
        // one group and 0.005. That is precisely the bug the kernel's own comment warns about, present in the
        // file whose comments call it "the reference implementation the Angular and Flutter tiers copy".
        //
        // What this method keeps is the half that is genuinely its own: assertions about the FIXTURE's SHAPE —
        // that a multi-rate case declares a breakdown, that every declared group matches a rate some line
        // carries, and that a case claiming the two rounding orders diverge really does diverge. Those are
        // properties of the data, not of the arithmetic, and they cannot be delegated.
        $currencyObject = Currency::of($currency);
        $documentRate = null === $singleVatRate ? null : Rate::fromPercentage($singleVatRate);
        $documentLines = [];

        foreach ($lines as $line) {
            $rate = isset($line['vat_rate']) ? Rate::fromPercentage($line['vat_rate']) : $documentRate;
            self::assertNotNull($rate, "case {$id}: every line needs a VAT rate");

            $documentLines[] = new DocumentLine(
                $line['quantity'],
                Money::of($line['unit_net'], $currencyObject),
                $rate,
            );
        }

        $charges = array_map(
            static fn(array $charge): FixedCharge => new FixedCharge(
                $charge['label'],
                Money::of($charge['amount'], $currencyObject),
            ),
            $fixedCharges,
        );

        $totals = new DocumentCalculator()->calculate(
            $documentLines,
            $charges,
            // THE CASE'S DECLARED POINT (round 5, R5K-3). This was the literal `VatRoundingPoint::PerRateGroup`
            // while the fixture declared no point at all -- so this tier and the cross-tier SSOT agreed by
            // coincidence. `DocumentTotalsTest` had the identical defect; fixing one and not the other is the
            // not-the-full-set-of-sites shape, and the suite caught it here.
            self::declaredPoint($vatRoundingPoint, $id),
            RoundingMode::HalfUp,
            $currencyObject,
        );

        foreach ($lines as $index => $line) {
            self::assertSame(
                $line['line_net'],
                $totals->lineNets()[$index]->amount(),
                "line net {$index}, case {$id}",
            );
        }

        self::assertSame($expectedSubtotal, $totals->subtotalNet()->amount(), "subtotal, case {$id}");
        self::assertSame($expectedVat, $totals->vatTotal()->amount(), "vat, case {$id}");
        self::assertSame($expectedTotal, $totals->total()->amount(), "total, case {$id}");

        // ---- FIXTURE-SHAPE assertions, which are this method's own and are not delegable.

        // A MULTI-RATE document must declare its breakdown. Guarding only on "a breakdown was declared" left
        // the sharpest variant open: emptying `vat_by_rate` outright removed every assertion about it and the
        // suite stayed green. A single-rate case carries its rate at the case level and legitimately has none.
        if (\count($totals->vatByRate()) > 1) {
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
                $totals->vatByRate(),
                'A declared breakdown must cover every rate present in the lines and no others. A rate string '
                . 'that matches no line makes its group silently unassertable.',
            );

            // Keyed by the CANONICAL percentage, which is how the kernel groups — so a fixture rate spelled
            // `19.0` is checked against the group it actually lands in rather than silently matching nothing.
            $computed = [];

            foreach ($totals->vatByRate() as $group) {
                $computed[$group->rate()->percentage()] = $group;
            }

            foreach ($vatByRate as $declaredGroup) {
                $key = Rate::fromPercentage($declaredGroup['rate'])->percentage();
                self::assertArrayHasKey(
                    $key,
                    $computed,
                    'No line carries rate "' . $declaredGroup['rate'] . '", so this group would never be '
                    . 'checked. Compared on the canonical percentage, because "19" and "19.0" are one rate.',
                );
                self::assertSame(
                    $declaredGroup['base'],
                    $computed[$key]->base()->amount(),
                    "base for rate {$declaredGroup['rate']}, case {$id}",
                );
                self::assertSame(
                    $declaredGroup['vat'],
                    $computed[$key]->vat()->amount(),
                    "vat for rate {$declaredGroup['rate']}, case {$id}",
                );
            }
        }

        // Where the fixture supplies it, prove the naive order gives a DIFFERENT answer — otherwise the case
        // above would pass under either order and pin nothing. Through the kernel's own PerLine arm, so this
        // no longer re-implements it either.
        if (null !== $divergentVat) {
            $perLine = new DocumentCalculator()->calculate(
                $documentLines,
                $charges,
                self::theOtherPoint(self::declaredPoint($vatRoundingPoint, $id)),
                RoundingMode::HalfUp,
                $currencyObject,
            );

            self::assertSame($divergentVat, $perLine->vatTotal()->amount(), "divergent-point VAT, case {$id}");
            self::assertNotSame(
                $expectedVat,
                $perLine->vatTotal()->amount(),
                "case {$id} claims the two rounding orders diverge, but they agree — so it pins nothing.",
            );
        }
    }

    /**
     * @return iterable<string, array{
     *     string, string, list<array<string, string>>, string, ?string, list<array<string, string>>,
     *     string, list<array<string, string>>, string, ?string, string
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
                $case['vat_if_per_line'] ?? $case['vat_if_per_rate_group'] ?? null,
                $case['vat_rounding_point'],
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

    /**
     * **THE CASE'S DECLARED POINT, MATCHED STRICTLY — the sibling shape, which this consumer did not have.**
     *
     * Round 6 (C-P3-2). The R5K-3 fix reached both consumers of `vat_rounding_point`, but only one of them
     * strictly: `DocumentTotalsTest::declaredPoint()` throws on an unrecognised value, and this file used
     * `'per_line' === $v ? PerLine : PerRateGroup`, under which a typo, a renamed value or a missing key
     * silently becomes `PerRateGroup` — reinstating, by the back door, exactly the implicit default the whole
     * finding was about.
     *
     * It was masked rather than live: `DocumentTotalsTest`'s fixture-integrity loop asserts the key exists and
     * is one of the two on every `document_totals` case, so a bad value fails there first. That protection is
     * cross-file and was undocumented at this site — narrow or delete that loop and this consumer goes silent
     * again, which is the fixture-pair blindness this project has already recorded once. Two consumers of one
     * declaration should refuse a bad declaration in the same way, and neither should depend on the other for it.
     */
    private static function declaredPoint(string $vatRoundingPoint, string $id): VatRoundingPoint
    {
        return match ($vatRoundingPoint) {
            'per_line' => VatRoundingPoint::PerLine,
            'per_rate_group' => VatRoundingPoint::PerRateGroup,
            default => throw new \LogicException(\sprintf(
                'document_totals case "%s" declares an unknown vat_rounding_point "%s".',
                $id,
                $vatRoundingPoint,
            )),
        };
    }

    /**
     * The point the case does NOT declare, for the divergence arm.
     *
     * A `match` on the enum rather than a ternary, so that adding a third `VatRoundingPoint` is a compile-time
     * problem here rather than a silent re-pairing of the two that already exist — the same reason
     * `declaredPoint()` above refuses an unknown string instead of defaulting.
     */
    private static function theOtherPoint(VatRoundingPoint $declared): VatRoundingPoint
    {
        return match ($declared) {
            VatRoundingPoint::PerLine => VatRoundingPoint::PerRateGroup,
            VatRoundingPoint::PerRateGroup => VatRoundingPoint::PerLine,
        };
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
