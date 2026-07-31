<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Document\DocumentCalculator;
use Twes\Domain\Document\DocumentLine;
use Twes\Domain\Document\FixedCharge;
use Twes\Domain\Document\Invoice;
use Twes\Domain\Document\VatGroup;
use Twes\Domain\Document\VatRoundingPoint;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Exception\CurrencyMismatch;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Shared\RoundingMode;

/**
 * The calculation kernel, driven ENTIRELY by the committed cross-tier vectors.
 *
 * **Every FIXTURE-DRIVEN expectation is read from `docs/spec/pricing-vectors.json`**, never written here,
 * because that file is the contract three implementations (PHP, TypeScript, Dart) must agree on and a test
 * asserting a locally-invented figure would let this tier drift while staying green.
 *
 * The claim used to read "not one expected number in this file is written by hand", and round 13 counted six
 * that are: the fixed-charge differential (19.000 / 0.100 / 119.100) and the empty document (0.000 x3). Both
 * scenarios have NO case in the fixture, so the absolute claim was also hiding a real coverage gap — nothing
 * pins whether an empty TND invoice renders `0.000`, `0.00` or `0` when `admin/` implements at Wave 8. The two
 * non-fixture tests are now named as such, and the gap is recorded rather than concealed by a false absolute.
 *
 * **The rounding ORDER is the thing under test, not the formula.** `pricing-and-documents.plan.md` rules
 * that VAT is grouped by rate and rounded **once per rate group on the summed base**, because that is what
 * an EN 16931 / Peppol validator recomputes — so a payload built any other way needs reconciliation. The
 * fixture pins both arms: the `vat-rounding-order-diverges` case carries the correct `vat` **and** the
 * `vat_if_rounded_per_line_which_is_WRONG` value, so the non-default mode is asserted against a committed
 * number too rather than against something this test invented.
 */
#[CoversClass(DocumentCalculator::class)]
#[CoversClass(DocumentLine::class)]
#[CoversClass(FixedCharge::class)]
#[CoversClass(\Twes\Domain\Document\DocumentTotals::class)]
#[CoversClass(\Twes\Domain\Document\VatGroup::class)]
final class DocumentTotalsTest extends TestCase
{
    private const string VECTORS = __DIR__ . '/../../../../docs/spec/pricing-vectors.json';

    /**
     * Guards the guard, for the same reason `PricingVectorsTest` does: a fixture that goes missing or loses
     * its cases would make every data-driven test below silently supply nothing and pass while testing
     * nothing at all.
     */
    public function testTheDocumentVectorsArePresentAndPopulated(): void
    {
        $cases = self::documentCases();

        self::assertGreaterThanOrEqual(3, \count($cases));

        // At least one case must pin the rounding ORDER, or nothing here distinguishes round(sum(x)) from
        // sum(round(x)) and the whole point of the file is unasserted.
        // `$case[0]`, because the provider wraps each case in the single-argument array PHPUnit needs.
        // Written without the index first, which made both filters below silently empty and this very
        // assertion fire — the guard catching its own author is the argument for having it.
        $pinsOrder = array_filter(
            $cases,
            static fn(array $case): bool => isset($case[0]['vat_if_rounded_per_line_which_is_WRONG']),
        );
        self::assertNotEmpty($pinsOrder, 'No case distinguishes per-rate-group from per-line VAT rounding.');

        // And at least one must carry MORE THAN ONE rate, or the grouping is untested: with a single rate
        // group, "group then round" and "round the whole subtotal" are indistinguishable.
        $multiRate = array_filter(
            $cases,
            static fn(array $case): bool => isset($case[0]['vat_by_rate'])
                && \count($case[0]['vat_by_rate']) > 1,
        );
        self::assertNotEmpty($multiRate, 'No case carries lines at different VAT rates.');

        // And at least one must carry a FRACTIONAL quantity whose line net needs rounding, or nothing
        // distinguishes sum-of-rounded-line-nets from rounded-once-on-the-exact-sum. Round 13 proved that gap
        // live: every line in every case had an exact product, and a mutant computing the subtotal as
        // round(sum(exact)) passed the whole suite. A count floor cannot notice a missing property — this file
        // has now learned that twice, once for a negative tie and once here.
        $fractional = array_filter(
            $cases,
            static fn(array $case): bool => isset($case[0]['subtotal_if_rounded_once_which_is_WRONG']),
        );
        self::assertNotEmpty(
            $fractional,
            'No case pins the LINE-NET rounding order. One line with a fractional quantity whose product is a '
            . 'tie is enough, and fractional quantities are the ordinary case for services.',
        );
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('documentCases')]
    public function testTheDocumentTotalMatchesTheSharedVectors(array $case): void
    {
        $totals = self::calculate($case, VatRoundingPoint::PerRateGroup);

        self::assertSame(
            $case['subtotal_net'],
            $totals->subtotalNet()->amount(),
            'subtotal net for ' . $case['id'],
        );
        self::assertSame($case['vat'], $totals->vatTotal()->amount(), 'VAT total for ' . $case['id']);
        self::assertSame(
            $case['expected']['total'],
            $totals->total()->amount(),
            'document total for ' . $case['id'],
        );
    }

    /**
     * Every LINE net too, not only the document figures.
     *
     * A kernel that got `quantity x unit_net` wrong on one line and compensated elsewhere would satisfy the
     * totals above. The fixture states each line's net, so there is no reason to check only the aggregate.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('documentCases')]
    public function testEveryLineNetMatchesTheSharedVectors(array $case): void
    {
        $totals = self::calculate($case, VatRoundingPoint::PerRateGroup);
        $lineNets = array_map(
            static fn(Money $net): string => $net->amount(),
            $totals->lineNets(),
        );

        self::assertSame(
            array_map(static fn(array $line): string => $line['line_net'], $case['lines']),
            $lineNets,
            'line nets for ' . $case['id'],
        );
    }

    /**
     * The VAT BREAKDOWN by rate, where the fixture states one.
     *
     * The document total can be right while the breakdown is wrong — and the breakdown is what goes into an
     * EN 16931 payload and onto the printed document, so it is a deliverable in its own right rather than an
     * intermediate value.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('documentCasesWithARateBreakdown')]
    public function testTheVatBreakdownByRateMatchesTheSharedVectors(array $case): void
    {
        $totals = self::calculate($case, VatRoundingPoint::PerRateGroup);
        $actual = [];

        foreach ($totals->vatByRate() as $group) {
            $actual[] = [
                'rate' => $group->rate()->percentage(),
                'base' => $group->base()->amount(),
                'vat' => $group->vat()->amount(),
            ];
        }

        $expected = array_map(
            static fn(array $group): array => [
                'rate' => Rate::fromPercentage($group['rate'])->percentage(),
                'base' => $group['base'],
                'vat' => $group['vat'],
            ],
            $case['vat_by_rate'],
        );

        self::assertSame($expected, $actual, 'VAT breakdown for ' . $case['id']);
    }

    /**
     * THE ROUNDING-ORDER ARM, asserted against the fixture's own WRONG value.
     *
     * `VatRoundingPoint` exists because the plan rules the rounding point "configurable per company, with
     * [per-rate-group] as the default" — so the other mode is a supported configuration, not dead code, and
     * it must be proven to produce the *different* number rather than quietly producing the same one. If
     * both modes returned the correct value, the parameter would be decorative and the default would be
     * pinned by nothing.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('documentCasesThatPinTheRoundingOrder')]
    public function testPerLineRoundingProducesTheFixturesDivergentValue(array $case): void
    {
        $perGroup = self::calculate($case, VatRoundingPoint::PerRateGroup);
        $perLine = self::calculate($case, VatRoundingPoint::PerLine);

        self::assertSame($case['vat'], $perGroup->vatTotal()->amount(), 'the DEFAULT is per rate group');
        self::assertSame(
            $case['vat_if_rounded_per_line_which_is_WRONG'],
            $perLine->vatTotal()->amount(),
            'per-line rounding must produce the fixture\'s divergent value, or the parameter does nothing',
        );
        self::assertNotSame(
            $perGroup->vatTotal()->amount(),
            $perLine->vatTotal()->amount(),
            'This case exists because the two modes DIVERGE. If they agree, it is testing nothing.',
        );
    }

    /**
     * A fixed document charge is in the TOTAL and in no VAT BASE.
     *
     * Tunisia's stamp duty is the worked example and it is `0.100 TND` — unrepresentable in a two-decimal
     * currency, which is why it is a first-class case rather than a footnote. Taxing it would be a silent
     * overcharge on every invoice, so the exclusion is asserted directly rather than inferred from the total
     * happening to match.
     */
    public function testAFixedDocumentChargeIsExcludedFromEveryVatBase(): void
    {
        $tnd = Currency::of('TND');

        $withoutCharge = new DocumentCalculator()->calculate(
            [new DocumentLine('1', Money::of('100.000', $tnd), Rate::fromPercentage('19'))],
            [],
            VatRoundingPoint::PerRateGroup,
            RoundingMode::HalfUp,
        );

        $withCharge = new DocumentCalculator()->calculate(
            [new DocumentLine('1', Money::of('100.000', $tnd), Rate::fromPercentage('19'))],
            [new FixedCharge('stamp_duty', Money::of('0.100', $tnd))],
            VatRoundingPoint::PerRateGroup,
            RoundingMode::HalfUp,
        );

        self::assertSame(
            $withoutCharge->vatTotal()->amount(),
            $withCharge->vatTotal()->amount(),
            'A fixed charge must not move the VAT. If it does, it entered a VAT base.',
        );
        self::assertSame('19.000', $withCharge->vatTotal()->amount());
        self::assertSame('0.100', $withCharge->fixedChargesTotal()->amount());
        self::assertSame('119.100', $withCharge->total()->amount());
    }

    /**
     * A document with no lines totals ZERO, and does not divide by anything.
     *
     * The empty document exists in the UI the instant somebody clicks "new invoice", so it is the first
     * state the kernel ever sees rather than an edge case.
     */
    public function testAnEmptyDocumentTotalsZeroRatherThanFailing(): void
    {
        $totals = new DocumentCalculator()->calculate(
            [],
            [],
            VatRoundingPoint::PerRateGroup,
            RoundingMode::HalfUp,
            Currency::of('TND'),
        );

        self::assertSame('0.000', $totals->subtotalNet()->amount());
        self::assertSame('0.000', $totals->vatTotal()->amount());
        self::assertSame('0.000', $totals->total()->amount());
        self::assertSame([], $totals->vatByRate());
    }

    /**
     * Mixing currencies within one document is REFUSED, not silently summed.
     *
     * `Money` already refuses cross-currency arithmetic, so this asserts the kernel surfaces that rather
     * than catching it — a document is single-currency by definition and the failure must name the document.
     */
    public function testADocumentMixingCurrenciesIsRefused(): void
    {
        $this->expectException(\Twes\Domain\Money\Exception\CurrencyMismatch::class);
        // THE MESSAGE, not just the class. `Money::plus()` throws the same class three lines downstream of this
        // guard, so asserting only the type left the guard entirely deletable with the suite green — round 14.
        // A crash and a detection are indistinguishable otherwise; this repo has recorded that twice.
        $this->expectExceptionMessage('document line 1');

        new DocumentCalculator()->calculate(
            [
                new DocumentLine('1', Money::of('1.000', Currency::of('TND')), Rate::fromPercentage('19')),
                new DocumentLine('1', Money::of('1.00', Currency::of('EUR')), Rate::fromPercentage('19')),
            ],
            [],
            VatRoundingPoint::PerRateGroup,
            RoundingMode::HalfUp,
        );
    }

    /**
     * A negative UNIT PRICE is refused, like a negative quantity — the same rule through the other door.
     *
     * Round 13 found the quantity guarded and the price not, so a line net of −5.000 and a document total of
     * −5.950 were constructible. That is the THIRD distinct route into the state the 2026-07-30 ruling refuses;
     * round 12 closed the second. A negative-total document is a credit note — EN 16931 type code 381, not
     * 380 — so this is a tax-document distinction, not a presentation one.
     */
    public function testANegativeUnitPriceIsRefusedLikeANegativeQuantity(): void
    {
        $tnd = Currency::of('TND');

        $refusals = 0;

        foreach ([['1', '-5.000'], ['-1', '5.000']] as [$quantity, $unit]) {
            try {
                new DocumentLine($quantity, Money::of($unit, $tnd), Rate::fromPercentage('19'));
            } catch (\InvalidArgumentException) {
                ++$refusals;
            }
        }

        self::assertSame(2, $refusals, 'Both doors into a negative line must be shut, not one.');

        // And ZERO is accepted on both, because a free-of-charge line is legitimate — a sample, a warranty
        // replacement, a promotional line. A guard written `<= 0` would refuse it.
        self::assertSame(
            '0.000',
            new DocumentLine('0', Money::of('5.000', $tnd), Rate::zero())
                ->net(RoundingMode::HalfUp)->amount(),
        );
        self::assertSame(
            '0.000',
            new DocumentLine('1', Money::of('0.000', $tnd), Rate::zero())
                ->net(RoundingMode::HalfUp)->amount(),
        );
    }

    /**
     * A FLOAT quantity is refused, and the union exists so that refusal is reachable.
     *
     * The parameter was a bare `string` while the docblock claimed "never a float … this refuses one for the
     * same reason [as `Money`]". It did not: from a weak-mode caller PHP coerced, and `0.1 + 0.2` —
     * `0.30000000000000004` in IEEE-754 — arrived as the string `'0.3'`, because implicit float-to-string uses
     * `precision=14`. The float's real value was discarded silently, which is the same laundering `Money`'s own
     * float guard exists to stop. Worse, the refusal that did happen (`1.0E+20`) was accidental — it depended
     * on the magnitude triggering exponent notation — so the invariant held for some floats and not others.
     *
     * THIS FILE CANNOT PROVE THE WEAK-MODE CASE, exactly as `MoneyTest` cannot: it declares `strict_types`, so
     * a float never reaches the constructor by coercion here. What it proves is that the arm exists and fires,
     * which is what a weak-mode caller then reaches. See `MoneyWeakModeTest` for the sibling proof.
     *
     */
    #[DataProvider('floatQuantities')]
    public function testAFloatQuantityIsRefused(float $quantity): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/float/');

        new DocumentLine($quantity, Money::of('5.000', Currency::of('TND')), Rate::zero());
    }

    /** @return iterable<string, array{float}> */
    public static function floatQuantities(): iterable
    {
        yield 'a plain fractional float' => [1.5];
        yield 'the canonical IEEE-754 artefact' => [0.1 + 0.2];
        yield 'a float that happens to be integral' => [1.0];
        yield 'a magnitude that renders in exponent form' => [1.0E+20];
    }

    /**
     * An INTEGER quantity is accepted, because it loses nothing.
     *
     * The union permits `int` for the same reason `Money::of()` does — `2` is exactly 2 — and refusing it would
     * make every caller cast for no benefit. Pinned so the float arm cannot be widened into an int refusal.
     */
    public function testAnIntegerQuantityIsAccepted(): void
    {
        self::assertSame(
            '10.000',
            new DocumentLine(2, Money::of('5.000', Currency::of('TND')), Rate::zero())
                ->net(RoundingMode::HalfUp)->amount(),
        );
    }

    /**
     * The SUBTOTAL is the sum of ROUNDED line nets, not the exact sum rounded once.
     *
     * Asserted against the fixture's own divergent value, exactly as the VAT rounding order is. `DocumentLine`
     * rounds each line because the line net is **printed** and summed into the printed subtotal, so printed
     * lines that do not add up to the printed subtotal are an EN 16931 validation failure. If both orders
     * produced the same number the rounding point would be pinned by nothing.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('documentCasesThatPinTheLineNetRoundingOrder')]
    public function testTheSubtotalIsTheSumOfRoundedLineNets(array $case): void
    {
        $totals = self::calculate($case, VatRoundingPoint::PerRateGroup);

        self::assertSame($case['subtotal_net'], $totals->subtotalNet()->amount());
        self::assertNotSame(
            $case['subtotal_if_rounded_once_which_is_WRONG'],
            $totals->subtotalNet()->amount(),
            'This case exists because the two orders DIVERGE. If they agree, it is testing nothing.',
        );

        // And every line net individually, so the subtotal cannot be right by two compensating errors.
        self::assertSame(
            array_map(static fn(array $line): string => $line['line_net'], $case['lines']),
            array_map(static fn(Money $net): string => $net->amount(), $totals->lineNets()),
        );
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function documentCasesThatPinTheLineNetRoundingOrder(): array
    {
        return array_filter(
            self::documentCases(),
            static fn(array $case): bool => isset($case[0]['subtotal_if_rounded_once_which_is_WRONG']),
        );
    }

    /**
     * The refusals that no mutant had ever tested — five of them, all revertible with the suite green.
     *
     * Round 13's completeness lens proved every one deletable: `resolveCurrency()`'s empty-document refusal
     * silently defaulting to TND, `DocumentLine`'s negative and malformed quantity guards, and `FixedCharge`'s
     * negative-amount and empty-label guards. `b39bdb4` claimed "eight mutants killed" and those eight were
     * real, but the SET was incomplete — the same shape as round 4's finding that three of round 3's four
     * tenancy fixes were revertible.
     *
     * The sharpest is the empty-document one: the mutant installs **the exact hazard the code's own comment
     * argues against** — "a default of TND would make a EUR company's new invoice silently three-decimal" —
     * and the suite reported OK.
     *
     * @param callable(): mixed $construction
     */
    #[DataProvider('everyUntestedRefusal')]
    public function testEveryRefusalInTheKernelIsLoadBearing(
        string $expectedException,
        string $messageFragment,
        callable $construction,
    ): void {
        $this->expectException($expectedException);
        $this->expectExceptionMessageMatches('/' . preg_quote($messageFragment, '/') . '/i');

        $construction();
    }

    /** @return iterable<string, array{string, string, callable}> */
    public static function everyUntestedRefusal(): iterable
    {
        $tnd = Currency::of('TND');

        yield 'an empty document with no currency to infer' => [
            \InvalidArgumentException::class,
            'no currency to infer',
            // Defaulting here would make a EUR company's new invoice silently three-decimal, which is the
            // hazard the code refuses to guess about.
            static fn(): mixed => new DocumentCalculator()->calculate(
                [],
                [],
                VatRoundingPoint::PerRateGroup,
                RoundingMode::HalfUp,
            ),
        ];
        yield 'a malformed quantity' => [
            \InvalidArgumentException::class,
            'not a well-formed decimal',
            static fn(): mixed => new DocumentLine('1,5', Money::of('1.000', $tnd), Rate::zero()),
        ];
        yield 'a negative fixed charge' => [
            \InvalidArgumentException::class,
            'is negative',
            // A negative charge silently REDUCES a document total with no VAT effect — an invisible discount
            // through a field that is not a discount.
            static fn(): mixed => new FixedCharge('rebate', Money::of('-1.000', $tnd)),
        ];
        yield 'an empty fixed-charge label' => [
            \InvalidArgumentException::class,
            'stable label',
            static fn(): mixed => new FixedCharge('   ', Money::of('1.000', $tnd)),
        ];
    }

    /**
     * A fixed charge's label is TRIMMED on store, not only on validate.
     *
     * The docblock calls the label "a stable identifier for the charge, e.g. `stamp_duty`", and
     * `' stamp_duty'` and `'stamp_duty'` are two distinct stable identifiers for one charge. The guard
     * validated `trim($label)` and stored `$label`.
     */
    public function testAFixedChargeLabelIsTrimmedOnStore(): void
    {
        $charge = new FixedCharge('  stamp_duty  ', Money::of('0.100', Currency::of('TND')));

        self::assertSame('stamp_duty', $charge->label());
    }

    /**
     * A negative VAT RATE is refused on a line, even though `Rate` permits negatives.
     *
     * `Rate` is right to permit them: it also serves as the PROFIT rate, where selling below cost is a real
     * commercial decision. But no jurisdiction has a negative VAT rate, and `DocumentLine` performed no range
     * check on the rate it was handed — so `Rate::fromPercentage('-19')` produced a document with VAT −19.000
     * and a total BELOW its net. One type serving two roles is exactly why the constraint belongs at the use
     * site rather than in `Rate`.
     */
    public function testANegativeVatRateIsRefusedOnALine(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no jurisdiction has a negative VAT rate/i');

        new DocumentLine('1', Money::of('100.000', Currency::of('TND')), Rate::fromPercentage('-19'));
    }

    /**
     * And a ZERO VAT rate is accepted, because zero-rated and exempt supplies are ordinary.
     *
     * A guard written `<= 0` would refuse every zero-rated line — the export, the exempt medical supply, the
     * intra-EU reverse charge — which is a large fraction of real invoices rather than an edge case.
     */
    public function testAZeroVatRateIsAccepted(): void
    {
        $totals = new DocumentCalculator()->calculate(
            [new DocumentLine('1', Money::of('100.000', Currency::of('TND')), Rate::zero())],
            [],
            VatRoundingPoint::PerRateGroup,
            RoundingMode::HalfUp,
        );

        self::assertSame('0.000', $totals->vatTotal()->amount());
        // And it is still its own GROUP, because a zero-rate subtotal is a required line on the document.
        self::assertCount(1, $totals->vatByRate());
    }

    // ------------------------------------------------------------------ fixture plumbing

    /**
     * @param array<string, mixed> $case
     */
    private static function calculate(array $case, VatRoundingPoint $point): object
    {
        $currency = Currency::of($case['currency']);
        $documentRate = isset($case['vat_rate']) ? Rate::fromPercentage($case['vat_rate']) : null;
        $lines = [];

        foreach ($case['lines'] as $line) {
            // A line's own rate wins; the document rate is the DEFAULT. Both shapes appear in the fixture —
            // the single-rate cases state it once at document level, the multi-rate case states it per line
            // — so a kernel supporting only one of them fails half the vectors.
            $rate = isset($line['vat_rate'])
                ? Rate::fromPercentage($line['vat_rate'])
                : $documentRate;

            self::assertNotNull($rate, 'Every line needs a rate, from the line or the document.');

            $lines[] = new DocumentLine($line['quantity'], Money::of($line['unit_net'], $currency), $rate);
        }

        $charges = array_map(
            static fn(array $charge): FixedCharge => new FixedCharge(
                $charge['label'],
                Money::of($charge['amount'], $currency),
            ),
            $case['fixed_charges'] ?? [],
        );

        return new DocumentCalculator()->calculate(
            $lines,
            $charges,
            $point,
            RoundingMode::HalfUp,
            $currency,
        );
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function documentCases(): array
    {
        /** @var array{document_totals: list<array<string, mixed>>} $vectors */
        $vectors = json_decode((string) file_get_contents(self::VECTORS), true, 512, \JSON_THROW_ON_ERROR);
        $cases = [];

        foreach ($vectors['document_totals'] as $case) {
            $cases[$case['id']] = [$case];
        }

        return $cases;
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function documentCasesWithARateBreakdown(): array
    {
        return array_filter(
            self::documentCases(),
            static fn(array $case): bool => isset($case[0]['vat_by_rate']),
        );
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function documentCasesThatPinTheRoundingOrder(): array
    {
        return array_filter(
            self::documentCases(),
            static fn(array $case): bool => isset($case[0]['vat_if_rounded_per_line_which_is_WRONG']),
        );
    }
    /**
     * **`VatGroup` IS A SECOND DOOR and round 14 found it unguarded.** These two cases exist because the object
     * that becomes the legal EN 16931 `TaxSubtotal` validated nothing while `DocumentLine` — the other way in —
     * refused both of these.
     */
    public function testAVatGroupRefusesANegativeRate(): void
    {
        $tnd = Currency::of('TND');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot carry a negative rate');

        new VatGroup(Rate::fromPercentage('-19'), Money::of('100.000', $tnd), Money::of('-19.000', $tnd));
    }

    /**
     * A EUR VAT figure beside a TND base is a legal document stating tax owed in neither currency.
     *
     * `Money` refuses to ADD across currencies, which is why this got through: nothing stopped the two amounts
     * being STORED side by side, and the pair is summed downstream and rendered as one `TaxSubtotal` row.
     */
    public function testAVatGroupRefusesABaseAndVatInDifferentCurrencies(): void
    {
        $this->expectException(CurrencyMismatch::class);

        new VatGroup(
            Rate::fromPercentage('19'),
            Money::of('100.000', Currency::of('TND')),
            Money::of('19.00', Currency::of('EUR')),
        );
    }

    /**
     * **A quantity is BOUNDED AT BOTH ENDS**, which round 14 found it was not — the one persisted decimal in
     * this domain with no bound at either end, and the one that multiplies money.
     *
     * `Money` caps integer digits and fractions; `Rate` caps both; `quantity` accepted 601 decimals and 40
     * integer digits, so the domain admitted values no `NUMERIC` a migration might choose could store, and there
     * was no constant for the migration to derive a precision from.
     *
     * @param string $quantity a quantity past one of the two bounds
     */
    #[DataProvider('unboundedQuantities')]
    public function testAQuantityPastEitherBoundIsRefused(string $quantity, string $expected): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expected);

        new DocumentLine($quantity, Money::of('10.000', Currency::of('TND')), Rate::zero());
    }

    /** @return iterable<string, array{string, string}> */
    public static function unboundedQuantities(): iterable
    {
        // GENERATED from the constants, so raising either bound moves its own case rather than leaving a stale
        // literal that passes for the wrong reason.
        yield 'one decimal past MAX_SCALE' => [
            '1.' . str_repeat('0', DocumentLine::MAX_SCALE) . '1',
            'decimal places',
        ];
        yield 'far past MAX_SCALE' => ['1.' . str_repeat('1', 601), 'decimal places'];
        yield 'one integer digit too many' => [
            str_repeat('9', DocumentLine::MAX_INTEGER_DIGITS + 1),
            'integer digits',
        ];
        yield 'far past MAX_INTEGER_DIGITS' => [str_repeat('9', 40), 'integer digits'];
    }

    /**
     * And BOTH boundaries are ACCEPTED, so the guards refuse excess rather than precision.
     *
     * Without this half, `>` and `>=` are indistinguishable — and the `>=` version would refuse a perfectly
     * ordinary six-decimal measure while looking like a working bound.
     */
    public function testAQuantityExactlyAtEitherBoundIsAccepted(): void
    {
        $tnd = Currency::of('TND');

        $atScale = new DocumentLine(
            '1.' . str_repeat('0', DocumentLine::MAX_SCALE - 1) . '1',
            Money::of('1.000', $tnd),
            Rate::zero(),
        );
        self::assertSame('1.000', $atScale->net(RoundingMode::HalfUp)->amount());

        // A NON-ZERO unit price, because the version of this assertion written at round 14 used `0.000` — so the
        // product was zero and the case could not see that the two bounds together admit an unrepresentable line
        // net. A test paired with a zero factor does not exercise a multiplication. Unit price `1.000` keeps the
        // product at exactly the accepted magnitude.
        $atDigits = new DocumentLine(
            str_repeat('9', DocumentLine::MAX_INTEGER_DIGITS),
            Money::of('1.000', $tnd),
            Rate::zero(),
        );
        self::assertSame(str_repeat('9', DocumentLine::MAX_INTEGER_DIGITS) . '.000', $atDigits->net(RoundingMode::HalfUp)->amount());
    }

    /**
     * **A mismatching FIXED CHARGE is refused too — the paired door, which had no test at all.**
     *
     * Both `resolveCurrency()` guards were deletable with the suite green; this one was not merely unasserted on
     * its message, it was unexercised. A charge is added straight to the total, so a EUR stamp duty on a TND
     * invoice is a wrong payable amount rather than a formatting problem.
     */
    public function testADocumentWhoseFixedChargeIsInAnotherCurrencyIsRefused(): void
    {
        $this->expectException(CurrencyMismatch::class);
        $this->expectExceptionMessage('document charge 0');

        new DocumentCalculator()->calculate(
            [new DocumentLine('1', Money::of('1.000', Currency::of('TND')), Rate::zero())],
            [new FixedCharge('stamp_duty', Money::of('0.10', Currency::of('EUR')))],
            VatRoundingPoint::PerRateGroup,
            RoundingMode::HalfUp,
        );
    }

    /**
     * And `Invoice::withFixedCharge()`'s own currency guard, whose `withLine()` twin was tested and it was not.
     *
     * Round 14: removing `withLine`'s guard killed a test; removing this one left the suite green. The guard was
     * present on both paths and the TEST on one — the "guard on one write path" shape inverted, and the reason
     * `testTheMutatorInventoryIsComplete` did not catch it is that it enforces the MUTABILITY dimension, not
     * this one.
     */
    public function testAnInvoiceRefusesAFixedChargeInAnotherCurrency(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Invoice::draft(Currency::of('TND'))
            ->withFixedCharge(new FixedCharge('stamp_duty', Money::of('0.10', Currency::of('EUR'))));
    }

    /**
     * **THE PRODUCT OF TWO IN-BOUNDS FACTORS IS REFUSED WHEN IT OVERFLOWS — round 15's P1.**
     *
     * `999999999999999` is accepted at exactly `MAX_INTEGER_DIGITS`, and `2.000 TND` is an ordinary unit price;
     * their product has SIXTEEN integer digits. `Invoice::issue()` computes no figures, so before this guard the
     * invoice was issued, its number consumed permanently and its state frozen, and `totals()` raised forever —
     * `cancel()` included, so the audit record could never be rendered.
     *
     * Verbatim the defect rounds 5 and 6 closed for `ProductPricing`: **matching two bounds says nothing about
     * their product.** Reinstalled at the next door, and the round-14 docblock actively asserted it could not
     * happen.
     */
    public function testTwoInBoundsFactorsWhoseProductOverflowsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('more integer digits than an amount can hold');

        new DocumentLine(
            str_repeat('9', DocumentLine::MAX_INTEGER_DIGITS),
            Money::of('2.000', Currency::of('TND')),
            Rate::zero(),
        );
    }

    /**
     * And the document can never be ISSUED in that state — asserted through the aggregate, not only the line.
     *
     * The line guard is the fix; this is the consequence that made it a P1 rather than a P3, and it is asserted
     * separately because a future refactor could move the check somewhere `Invoice` does not reach.
     */
    public function testAnInvoiceCannotBeIssuedWithAnUnrepresentableLineNet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Invoice::draft(Currency::of('TND'))->withLine(new DocumentLine(
            str_repeat('9', DocumentLine::MAX_INTEGER_DIGITS),
            Money::of('2.000', Currency::of('TND')),
            Rate::zero(),
        ));
    }

    /**
     * **The two quantity bounds have COMMITTED MINIMA**, because every refusal case is generated from the
     * constants — so raising either silently moves its own case and the suite stays green.
     *
     * Round 15 proved it: `MAX_SCALE` 6→7, `MAX_INTEGER_DIGITS` 15→16 and 15→**3** all survived with 523/523
     * green, and the last of those refuses a quantity of `1000` — an ordinary bulk line. That is verbatim round
     * 4's `Rate::MAX_INTEGER_DIGITS = 3` P1. CLAUDE.md records the remedy for exactly this shape under
     * `test-gates.sh`: "committed minimum rule-set SIZES, because generating a case from the data means deleting
     * an entry deletes its own case".
     *
     * `MAX_INTEGER_DIGITS` is asserted EQUAL to `Money`'s rather than merely bounded, because the docblock's
     * stated reason for the value is that it matches — and nothing enforced that either.
     */
    public function testTheQuantityBoundsHaveNotDrifted(): void
    {
        // EXACT, not a minimum — which is what round 15's surviving `6 -> 7` mutant showed a minimum cannot do.
        // `build-waves.plan.md` derives `document_line.quantity NUMERIC(21,6)` from this constant, so RAISING it
        // is as much a divergence as lowering it: the domain would accept a 7-decimal quantity the column cannot
        // store, which is the exact mismatch this constant was introduced to eliminate. Changing it is therefore
        // a MIGRATION, and this assertion is what makes that a deliberate act rather than an edit.
        self::assertSame(
            6,
            DocumentLine::MAX_SCALE,
            'MAX_SCALE is the scale of document_line.quantity NUMERIC(21,6). Changing it here without changing '
            . 'the column makes the domain accept values persistence rejects (raising) or refuse ordinary '
            . 'measures like hours and cubic metres (lowering). Change both, in one commit.',
        );
        self::assertSame(
            Money::MAX_INTEGER_DIGITS,
            DocumentLine::MAX_INTEGER_DIGITS,
            'MAX_INTEGER_DIGITS is documented as matching Money deliberately; nothing enforced it. Lowering it '
            . 'refuses ordinary bulk quantities, and raising it admits products Money cannot hold.',
        );

        // And an ORDINARY quantity is accepted, which is the assertion a lowered bound actually breaks. Generated
        // cases cannot catch that: they move with the constant.
        $line = new DocumentLine('1000', Money::of('12.000', Currency::of('TND')), Rate::zero());
        self::assertSame('12000.000', $line->net(RoundingMode::HalfUp)->amount());
    }

    /**
     * **A DOCUMENT whose SUM cannot be totalled is refused at the edit — round 16 P1.**
     *
     * The line guard bounds `quantity × unitNet`; nothing bounded the sum, so an invoice could be ISSUED — number
     * consumed permanently from a gapless legal sequence, state frozen — and `totals()` then raised forever,
     * `cancel()` included. `Money`'s own docblock already named the shape: *"two representable amounts can sum to
     * an unrepresentable one"*. Third iteration of one defect: `ProductPricing` (r5-6), the line product (r15),
     * the sum (r16).
     *
     * @param callable(): mixed $build
     */
    #[DataProvider('documentsThatCannotBeTotalled')]
    public function testADocumentWhoseSumCannotBeTotalledIsRefusedAtTheEdit(callable $build): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('impossible to total');

        $build();
    }

    /** @return iterable<string, array{callable}> */
    public static function documentsThatCannotBeTotalled(): iterable
    {
        $tnd = Currency::of('TND');
        $huge = str_repeat('9', DocumentLine::MAX_INTEGER_DIGITS);

        // ONE line: the net fits and net + VAT does not. The single-line route matters because it shows the
        // defect was never about accumulation.
        yield 'one line whose net fits but whose gross does not' => [
            static fn(): Invoice => Invoice::draft($tnd)
                ->withLine(new DocumentLine($huge, Money::of('1.000', $tnd), Rate::fromPercentage('19'))),
        ];
        // TWO lines, each exactly representable, summing past the bound.
        yield 'two lines each in bounds, summing out of bounds' => [
            static fn(): Invoice => Invoice::draft($tnd)
                ->withLine(new DocumentLine($huge, Money::of('1.000', $tnd), Rate::zero()))
                ->withLine(new DocumentLine($huge, Money::of('1.000', $tnd), Rate::zero())),
        ];
        // And through the CHARGE door, which is the paired path and was equally open.
        yield 'a fixed charge pushing the total out of bounds' => [
            static fn(): Invoice => Invoice::draft($tnd)
                ->withLine(new DocumentLine($huge, Money::of('1.000', $tnd), Rate::zero()))
                ->withFixedCharge(new FixedCharge('stamp_duty', Money::of($huge . '.000', $tnd))),
        ];
    }

    /**
     * The line guard rounds with `RoundingMode::Up` — pinned, because reverting it to `Down` reinstates the
     * defect and round 16 found nothing caught that.
     *
     * `Up` is away-from-zero and therefore the largest magnitude any mode can produce, which is what makes the
     * check complete rather than leaving the carry edge open. The distinguishing input is a quantity whose exact
     * product is just under the bound and rounds OVER it.
     */
    public function testTheLineGuardRoundsAwayFromZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DocumentLine(
            str_repeat('9', DocumentLine::MAX_INTEGER_DIGITS) . '.999999',
            Money::of('1.000', Currency::of('TND')),
            Rate::zero(),
        );
    }

    /**
     * And it rounds at the CURRENCY's own scale, not a hardcoded one — pinned, because hardcoding 2 survived and
     * that is this project's headline invariant (TND has three).
     *
     * The distinguishing input is a legitimate three-decimal TND line that a two-decimal assumption refuses.
     */
    public function testTheLineGuardUsesTheCurrencysOwnScale(): void
    {
        // THE DISTINGUISHING INPUT, and the first version of this test did not have it: with 14 integer digits a
        // scale-2 rounding never crosses the bound, so both scales accepted and the mutant survived. At 15 the
        // exact product is 999999999999999.995 — scale 3 keeps 15 integer digits, scale 2 carries to SIXTEEN and
        // refuses a legitimate TND line. A test whose input cannot tell two behaviours apart is not a test.
        $quantity = str_repeat('9', DocumentLine::MAX_INTEGER_DIGITS) . '.995';

        $line = new DocumentLine($quantity, Money::of('1.000', Currency::of('TND')), Rate::zero());

        self::assertSame(
            $quantity,
            $line->net(RoundingMode::HalfUp)->amount(),
            'A three-decimal TND quantity at the magnitude bound is legitimate. A hardcoded scale of 2 rounds it '
            . 'up to sixteen integer digits and refuses it — and TND having three decimals is this project\'s '
            . 'headline invariant.',
        );
    }

}
