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
use Twes\Domain\Document\DocumentTotals;
use Twes\Domain\Document\FixedCharge;
use Twes\Domain\Document\Invoice;
use Twes\Domain\Document\VatGroup;
use Twes\Domain\Document\VatRoundingPoint;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Exception\CurrencyMismatch;
use Twes\Domain\Money\Exception\InvalidMoneyAmount;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\PriceCalculator;
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
 * `vat_if_per_line` value, so the non-default point is asserted against a committed
 * number too rather than against something this test invented.
 */
#[CoversClass(DocumentCalculator::class)]
#[CoversClass(DocumentLine::class)]
#[CoversClass(FixedCharge::class)]
#[CoversClass(\Twes\Domain\Document\DocumentTotals::class)]
#[CoversClass(\Twes\Domain\Document\VatGroup::class)]
final class DocumentTotalsTest extends TestCase
{
    /**
     * Every invoice fixture is addressed to a client, because since 2026-08-22 `issue()`
     * requires one — EN 16931 makes the buyer mandatory (BT-44) and an issued invoice
     * addressed to nobody is not a document a tax authority accepts. A DRAFT may have none;
     * these fixtures carry one because a realistic invoice does.
     */
    private const FIXTURE_CLIENT = '0199a5b2-0000-7000-8000-00000000c101';

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
            static fn(array $case): bool => isset($case[0]['vat_if_per_line']),
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

        // EVERY CASE DECLARES ITS VAT ROUNDING POINT (round 5, R5K-3).
        //
        // The fixture computed every expected figure under `per_rate_group` and said so NOWHERE. That is the
        // one thing a cross-tier arithmetic SSOT may not leave implicit: `PerRateGroup` and `PerLine` are BOTH
        // ruled settings that a tenant selects, they produce numerically different tax on the same lines, and a
        // tier reading these vectors had nothing to tell it which one it was being measured against. It would
        // have implemented whichever it guessed and passed.
        //
        // Worse, the divergence column was named `vat_if_rounded_per_line_which_is_WRONG`, so the SSOT actively
        // asserted that the other ruled setting produces a wrong answer. It does not — it produces that
        // setting's answer.
        foreach ($cases as $id => [$case]) {
            self::assertArrayHasKey(
                'vat_rounding_point',
                $case,
                \sprintf(
                    'document_totals case "%s" does not declare vat_rounding_point. Both points are ruled '
                    . 'settings producing different tax on the same lines, so an expected figure without one '
                    . 'names no arithmetic at all.',
                    $id,
                ),
            );
            self::assertContains(
                $case['vat_rounding_point'],
                ['per_rate_group', 'per_line'],
                \sprintf('document_totals case "%s" declares an unknown vat_rounding_point.', $id),
            );
        }

        // AND EVERY INPUT RATE IS IN HUMAN FORM — the mirror of `PricingVectorsTest`'s assertion that every
        // EXPECTED rate is canonical, which existed while this direction did not. `conventions.rates` rules that
        // inputs are written the way a user types them (`19`, not `19.0000000000`) precisely so these vectors
        // prove a tier CANONICALISES, and a pre-canonicalised input silently removes the only evidence of that:
        // both spellings parse to the identical rate, so no expected value moves and nothing goes red.
        //
        // Round 17's P2-5 was exactly that — the three `per-line-vat-allocation-*` cases carried
        // `19.0000000000`, landing one commit BEFORE the convention forbidding it, and the fix was revertible
        // with the whole suite green until this assertion existed. `document_totals` is the section THIS file
        // consumes; the `cases`, `edit_directions` and `authored_field` inputs are `PricingVectorsTest`'s to
        // guard, and that half is still owed.
        foreach ($cases as $id => [$case]) {
            $rates = [$case['vat_rate'] ?? null];

            foreach ($case['lines'] as $line) {
                $rates[] = $line['vat_rate'] ?? null;
            }

            foreach (array_filter($rates, static fn(?string $rate): bool => null !== $rate) as $rate) {
                self::assertDoesNotMatchRegularExpression(
                    '/^-?\d+\.\d{10}$/',
                    $rate,
                    \sprintf(
                        'Case "%s" supplies the INPUT rate "%s" already canonicalised to 10 decimals. An input '
                        . 'written the way `Rate::fromPercentage()` renders it cannot prove any tier performs '
                        . 'that canonicalisation — write it the way a human types it (`19`). Only '
                        . 'vat_by_rate[].rate, which is EXPECTED OUTPUT, is canonical.',
                        (string) $id,
                        $rate,
                    ),
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('documentCases')]
    public function testTheDocumentTotalMatchesTheSharedVectors(array $case): void
    {
        $totals = self::calculate($case, self::declaredPoint($case));

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
        $totals = self::calculate($case, self::declaredPoint($case));
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
        $totals = self::calculate($case, self::declaredPoint($case));
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
            $case['vat_if_per_line'],
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
        $totals = self::calculate($case, self::declaredPoint($case));

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
        // ADDED WITH THE WRITE PATH. Until an HTTP endpoint accepted a label, an unbounded one was theoretical;
        // `document_charge.label` is `TEXT`, so nothing downstream refuses any length either. This was the last
        // persisted value in the domain with no bound at all — round 14's `quantity` finding, one field over.
        yield 'a fixed-charge label longer than MAX_LABEL_LENGTH' => [
            \InvalidArgumentException::class,
            'at most ' . FixedCharge::MAX_LABEL_LENGTH . ' are allowed',
            static fn(): mixed => new FixedCharge(
                str_repeat('a', FixedCharge::MAX_LABEL_LENGTH + 1),
                Money::of('1.000', $tnd),
            ),
        ];
    }

    /**
     * THE LABEL BOUND IS IN CHARACTERS, NOT BYTES — so an Arabic label is not refused for being Arabic.
     *
     * `strlen()` would make the bound depend on the alphabet: a 64-character Arabic label is 128 bytes in UTF-8, and
     * an Arabic-speaking tenant would find the field half as long as the documentation says. This project ships `ar`
     * as a first-class locale and vendors a font specifically so Arabic renders, so a byte bound here would be a
     * defect rather than a limitation.
     *
     * The mutant: swap `mb_strlen($label, 'UTF-8')` for `strlen($label)` and this case fails while every other label
     * case stays green, because they are all ASCII.
     */
    public function testTheFixedChargeLabelBoundIsMeasuredInCharactersRatherThanBytes(): void
    {
        // Arabic "rasm" (a charge), repeated to exactly the bound. Multi-byte in UTF-8, so `strlen` sees far more.
        $label = str_repeat('رسم', (int) (FixedCharge::MAX_LABEL_LENGTH / 3));

        self::assertLessThanOrEqual(FixedCharge::MAX_LABEL_LENGTH, mb_strlen($label, 'UTF-8'), 'fixture sanity');
        self::assertGreaterThan(FixedCharge::MAX_LABEL_LENGTH, \strlen($label), 'the fixture must expose the bug');

        self::assertSame(
            $label,
            new FixedCharge($label, Money::of('1.000', Currency::of('TND')))->label(),
            'a label within the CHARACTER bound must be accepted whatever its byte length',
        );
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
     * **THE CASE'S OWN DECLARED ROUNDING POINT (round 5, R5K-3).**
     *
     * Every call site here used to pass `VatRoundingPoint::PerRateGroup` as a literal while the fixture declared
     * no point at all, so the tier and the SSOT agreed by coincidence rather than by contract. Reading it from
     * the case is what makes a `per_line` vector assertable at all -- and what stops a future case being added
     * under one point and silently measured under the other.
     *
     * @param array<string, mixed> $case
     */
    private static function declaredPoint(array $case): VatRoundingPoint
    {
        return match ($case['vat_rounding_point']) {
            'per_line' => VatRoundingPoint::PerLine,
            'per_rate_group' => VatRoundingPoint::PerRateGroup,
            // A case declaring something else fails the fixture-integrity assertion above; this arm exists so a
            // typo cannot fall through to a silent default, which is how the implicit `PerRateGroup` arose.
            default => throw new \LogicException(\sprintf(
                'document_totals case "%s" declares an unknown vat_rounding_point.',
                (string) ($case['id'] ?? '?'),
            )),
        };
    }

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
            static fn(array $case): bool => isset($case[0]['vat_if_per_line']),
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

        Invoice::draft(Currency::of('TND'))->withClient(self::FIXTURE_CLIENT)
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

        Invoice::draft(Currency::of('TND'))->withClient(self::FIXTURE_CLIENT)->withLine(new DocumentLine(
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
            static fn(): Invoice => Invoice::draft($tnd)->withClient(self::FIXTURE_CLIENT)
                ->withLine(new DocumentLine($huge, Money::of('1.000', $tnd), Rate::fromPercentage('19'))),
        ];
        // TWO lines, each exactly representable, summing past the bound.
        yield 'two lines each in bounds, summing out of bounds' => [
            static fn(): Invoice => Invoice::draft($tnd)->withClient(self::FIXTURE_CLIENT)
                ->withLine(new DocumentLine($huge, Money::of('1.000', $tnd), Rate::zero()))
                ->withLine(new DocumentLine($huge, Money::of('1.000', $tnd), Rate::zero())),
        ];
        // ROUND 5, R5C-1 -- THE PROBE ITSELF WAS THE SMALLER CONFIGURATION, so the guard under-estimated.
        //
        // `totallable()` probed `PerRateGroup, Up` and justified it with *"`PerLine` cannot exceed it: rounding
        // each line up and summing is bounded by rounding the summed base up."* That bound is INVERTED --
        // `ceil(a) + ceil(b) >= ceil(a+b)` -- so under `Up` it is `PerLine` that dominates, and a document could
        // pass the guard and then be impossible to total under a rounding point a tenant setting may select.
        //
        // These two lines are that document, solved rather than guessed: the group total lands EXACTLY on the
        // 15-integer-digit bound and the per-line sum spends one more millime. The window is a single quantum
        // wide, which is why no incidental case had ever hit it.
        //
        //   PerRateGroup/Up ->  999999999999999.999   (representable, so the old guard passed)
        //   PerLine     /Up -> 1000000000000000.000   (16 integer digits -- refused by `Money`)
        //
        // The consequence is the one this whole test class exists for: such a draft could be ISSUED, spending a
        // number permanently from a gapless legal sequence, and then never rendered under that setting.
        yield 'lines the group rounding tolerates and the per-line rounding does not' => [
            static fn(): Invoice => Invoice::draft($tnd)->withClient(self::FIXTURE_CLIENT)
                ->withLine(new DocumentLine('1', Money::of('947867298578199.012', $tnd), Rate::fromPercentage('5.5')))
                ->withLine(new DocumentLine('1', Money::of('0.039', $tnd), Rate::fromPercentage('5.5'))),
        ];
        // And through the CHARGE door, which is the paired path and was equally open.
        yield 'a fixed charge pushing the total out of bounds' => [
            static fn(): Invoice => Invoice::draft($tnd)->withClient(self::FIXTURE_CLIENT)
                ->withLine(new DocumentLine($huge, Money::of('1.000', $tnd), Rate::zero()))
                ->withFixedCharge(new FixedCharge('stamp_duty', Money::of($huge . '.000', $tnd))),
        ];
    }

    /**
     * **THE OTHER DIRECTION, WHICH A TIGHTENED GUARD IS EXACTLY HOW YOU BREAK (R4C-6).**
     *
     * R5C-1 moved the probe from `PerRateGroup, Up` to `PerLine, Up`, which refuses strictly MORE documents. A
     * guard tightened without this case is how a perfectly valid invoice becomes unissuable — and that failure
     * is worse than the one being fixed, because it stops legitimate work rather than deferring an error.
     *
     * So: a document that every rounding point and every rounding mode can total must still be ACCEPTED, and
     * every figure on the result must be reachable. `Unnecessary` is excluded deliberately and the reason is in
     * `totallable()`'s docblock — it constrains the INPUTS rather than the magnitude, so no magnitude probe can
     * ever satisfy it.
     */
    public function testADocumentEveryConfigurationCanTotalIsStillAccepted(): void
    {
        $tnd = Currency::of('TND');

        // Ordinary, and deliberately awkward: three lines, a fractional quantity, two different rates and a
        // fixed charge, so the accepted path covers the same doors the refused one does.
        $document = Invoice::draft($tnd)->withClient(self::FIXTURE_CLIENT)
            ->withLine(new DocumentLine('3', Money::of('12.345', $tnd), Rate::fromPercentage('19')))
            ->withLine(new DocumentLine('0.5', Money::of('7.777', $tnd), Rate::fromPercentage('7')))
            ->withLine(new DocumentLine('11', Money::of('0.123', $tnd), Rate::fromPercentage('19')))
            ->withFixedCharge(new FixedCharge('stamp_duty', Money::of('0.100', $tnd)));

        $configurations = 0;
        foreach (VatRoundingPoint::cases() as $point) {
            foreach (RoundingMode::cases() as $mode) {
                if (RoundingMode::Unnecessary === $mode) {
                    continue;
                }

                $totals = $document->totals($point, $mode);

                // EVERY accessor, not just `total()`. Each is its own `Money` construction and can overflow
                // independently, so a probe validated against the total alone would not have proven this.
                self::assertNotEmpty($totals->lineNets());
                self::assertNotEmpty($totals->vatByLine());
                self::assertNotEmpty($totals->vatByRate());
                // Asserted rather than merely CALLED: a bare accessor call is a statement with no effect, which
                // PHPStan refuses -- rightly, since a reader cannot tell "this must not throw" from a leftover.
                // Every figure is a `Money` in the document's own currency, which is the property being claimed.
                self::assertSame($tnd, $totals->subtotalNet()->currency());
                self::assertSame($tnd, $totals->vatTotal()->currency());
                self::assertSame($tnd, $totals->fixedChargesTotal()->currency());
                self::assertSame($tnd, $totals->total()->currency());

                ++$configurations;
            }
        }

        // ANTI-VACUITY: a loop that ran zero times would assert nothing while looking thorough.
        self::assertGreaterThan(0, $configurations);
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

    /**
     * The reported position is the POSITION, not the array key — round 16 found round 15's `array_values()` fix
     * revertible with the suite green.
     *
     * `list<DocumentLine>` was a docblock claim that nothing enforced. PHPStan now DOES (level 6 over `tests/` too),
     * and it is what found the annotation and this test contradicting each other — the annotation was the one
     * that was wrong, and both parameters now read `array<int, …>`.
     * So a non-sequential caller made the message name "document line 8" while `DocumentTotals::lineNets()` exposes
     * 0 and 1. An index a client is told to fix has to be the index the client can see.
     */
    public function testTheReportedPositionIsThePositionAndNotTheArrayKey(): void
    {
        $tnd = Currency::of('TND');
        $eur = Currency::of('EUR');

        try {
            new DocumentCalculator()->calculate(
                [7 => new DocumentLine('1', Money::of('1.000', $tnd), Rate::zero()),
                    8 => new DocumentLine('1', Money::of('1.00', $eur), Rate::zero())],
                [],
                VatRoundingPoint::PerRateGroup,
                RoundingMode::HalfUp,
            );

            self::fail('A document mixing currencies must be refused.');
        } catch (CurrencyMismatch $mismatch) {
            self::assertStringContainsString('document line 1', $mismatch->getMessage());
            self::assertStringNotContainsString(
                'document line 8',
                $mismatch->getMessage(),
                'The array KEY must not reach the message: DocumentTotals::lineNets() exposes 0 and 1.',
            );
        }
    }

    /**
     * **PER-LINE VAT: the shares sum EXACTLY to the VAT total.** Required by developer ruling, 2026-07-31.
     *
     * This is the whole reason the figure is ALLOCATED rather than recomputed. Under `PerRateGroup` the group's VAT
     * is rounded once on the summed base, so `lineNet × rate` rounded per line does not add up to it: two `0.013`
     * TND lines at 19% give a group VAT of `0.005` while each line's own rounded VAT is `0.002` — `0.004`, a
     * millime short. A line column that does not sum to the total printed beneath it is a document a tax authority
     * reads as arithmetically wrong.
     */
    public function testPerLineVatSumsExactlyToTheVatTotal(): void
    {
        $tnd = Currency::of('TND');
        $totals = new DocumentCalculator()->calculate(
            [
                new DocumentLine('1', Money::of('0.013', $tnd), Rate::fromPercentage('19')),
                new DocumentLine('1', Money::of('0.013', $tnd), Rate::fromPercentage('19')),
            ],
            [],
            VatRoundingPoint::PerRateGroup,
            RoundingMode::HalfUp,
            $tnd,
        );

        self::assertSame('0.005', $totals->vatTotal()->amount(), 'the group figure is rounded once on the base');
        self::assertCount(2, $totals->vatByLine());

        // The millime goes to ONE of the two lines, and which one is deterministic — but the assertion that
        // matters is the SUM, because that is the property a document is judged on.
        $sum = Money::zero($tnd);

        foreach ($totals->vatByLine() as $share) {
            $sum = $sum->plus($share);
        }

        self::assertSame(
            $totals->vatTotal()->amount(),
            $sum->amount(),
            'The per-line VAT column MUST sum to the VAT total. Recomputing lineNet x rate per line gives 0.004 '
            . 'against a total of 0.005.',
        );
        self::assertSame(['0.003', '0.002'], array_map(
            static fn(Money $m): string => $m->amount(),
            $totals->vatByLine(),
        ), 'largest remainder, ties to the earliest line');
    }

    /**
     * **The shares are FLOORED before the remainder is handed out, so the sum can only ever be SHORT.**
     *
     * Rounding each line to NEAREST instead lets the shares EXCEED the group figure — and there is then no way to
     * take a millime back from a line without picking a victim, which is the arbitrariness the allocator exists to
     * avoid. The distinguishing input needs per-line rounding to overshoot: three `0.008 TND` lines at 19% give an
     * exact `0.00152` each, which rounds to `0.002` and sums to `0.006` against a group VAT of `0.005`. My first
     * version of these tests used two `0.013` lines, where per-line rounding UNDERshoots — so a
     * round-to-nearest mutant survived a test written to catch it.
     */
    public function testPerLineSharesAreFlooredSoTheSumIsNeverOverTheGroupFigure(): void
    {
        $tnd = Currency::of('TND');
        $totals = new DocumentCalculator()->calculate(
            array_fill(0, 3, new DocumentLine('1', Money::of('0.008', $tnd), Rate::fromPercentage('19'))),
            [],
            VatRoundingPoint::PerRateGroup,
            RoundingMode::HalfUp,
            $tnd,
        );

        self::assertSame('0.005', $totals->vatTotal()->amount());
        self::assertSame(['0.002', '0.002', '0.001'], array_map(
            static fn(Money $m): string => $m->amount(),
            $totals->vatByLine(),
        ), 'Rounding each line to nearest would give 0.002 x3 = 0.006, which exceeds the group VAT of 0.005.');
    }

    /**
     * **The remainder goes to the LARGEST claim, not the smallest** — with unequal remainders, so the direction of
     * the comparison is observable.
     *
     * Two `0.013` lines have EQUAL remainders, so largest-first and smallest-first pick the same set there and a
     * reversed comparison survived. Here the nets differ: `0.013` gives an exact `0.00247` (remainder `0.00047`)
     * and `0.021` gives `0.00399` (remainder `0.00099`), so the second line has the stronger claim to the odd
     * millime and must receive it.
     */
    public function testTheRemainderGoesToTheLineWithTheLargestClaim(): void
    {
        $tnd = Currency::of('TND');
        $totals = new DocumentCalculator()->calculate(
            [
                new DocumentLine('1', Money::of('0.013', $tnd), Rate::fromPercentage('19')),
                new DocumentLine('1', Money::of('0.021', $tnd), Rate::fromPercentage('19')),
            ],
            [],
            VatRoundingPoint::PerRateGroup,
            RoundingMode::HalfUp,
            $tnd,
        );

        self::assertSame('0.006', $totals->vatTotal()->amount());
        self::assertSame(['0.002', '0.004'], array_map(
            static fn(Money $m): string => $m->amount(),
            $totals->vatByLine(),
        ), 'The 0.021 line has the larger remainder (0.00099 vs 0.00047) and must get the odd millime.');
    }

    /**
     * And it holds for EVERY document the fixture describes, plus a randomised sweep — because a rounding
     * allocation that is right on one example and wrong on another is worse than none.
     *
     * **BOTH ROUNDING POINTS, and that is the whole reason this loop exists** (round 18's F1). Every `PerLine`
     * assertion in this file used to be a two-line single-rate document, and this test — the only one driven by
     * the fixture's own multi-rate cases — passed `PerRateGroup` alone. So `sharesAsRounded()` was covered by
     * nothing under the mode it exists to serve, and reading its per-line array by the loop's group-relative
     * POSITION instead of the line's absolute position survived the entire suite: a one-token change that
     * printed a VAT column 78% over its own total on the fixture's interleaved case. Group-relative position
     * equals absolute position for every single-group document, which is exactly why single-rate tests cannot
     * see it.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('documentCases')]
    public function testPerLineVatSumsToTheTotalForEveryFixtureCase(array $case): void
    {
        foreach ([VatRoundingPoint::PerRateGroup, VatRoundingPoint::PerLine] as $roundingPoint) {
            $totals = self::calculate($case, $roundingPoint);
            $sum = Money::zero($totals->vatTotal()->currency());
            $where = $roundingPoint->value . ' ' . $case['id'];

            foreach ($totals->vatByLine() as $share) {
                $sum = $sum->plus($share);
                self::assertFalse(
                    $share->isNegative(),
                    'no line may carry a negative share of a positive VAT: ' . $where,
                );
            }

            self::assertSame($totals->vatTotal()->amount(), $sum->amount(), $where);
            self::assertCount(
                \count($totals->lineNets()),
                $totals->vatByLine(),
                'one share per line, always: ' . $where,
            );
        }
    }

    /**
     * **Under `PerLine`, every line's share is its OWN rounded VAT — asserted per line, on a MULTI-RATE document.**
     *
     * The sum assertion above catches a share that is wrong in magnitude; it cannot catch one that is wrong in
     * ATTRIBUTION, because a permutation sums identically. This asserts the identity itself against
     * `PriceCalculator::vat()` for each line independently, which is the property `sharesAsRounded()`'s docblock
     * claims and which nothing checked. Driven by the fixture's interleaved case rather than a hand-built one, so
     * a tier reading the vectors is held to the same rule.
     */
    public function testUnderPerLineEachLinesShareIsItsOwnRoundedVatOnAMultiRateDocument(): void
    {
        $cases = array_filter(
            self::documentCases(),
            static fn(array $case): bool => \count(array_unique(array_column($case[0]['lines'], 'vat_rate'))) > 1,
        );

        self::assertNotEmpty($cases, 'the fixture must keep at least one MULTI-RATE document; F1 hid behind that');

        $prices = new PriceCalculator();

        foreach ($cases as [$case]) {
            $totals = self::calculate($case, VatRoundingPoint::PerLine);

            foreach ($case['lines'] as $position => $line) {
                self::assertSame(
                    $prices->vat(
                        $totals->lineNets()[$position],
                        Rate::fromPercentage($line['vat_rate']),
                        RoundingMode::HalfUp,
                    )->amount(),
                    $totals->vatByLine()[$position]->amount(),
                    \sprintf('%s line %d must keep its own rounded VAT', $case['id'], $position),
                );
            }

        }
    }

    /**
     * **The allocator REFUSES a group VAT the floored shares already exceed, rather than clamping.**
     *
     * Round 18's F3. Replacing the old `max(0, $units)` clamp with a loud `\LogicException` was a deliberate
     * behavioural change — the anti-bandaid gate forbids a clamp with no stated failure mode — and it was
     * observed by nothing: both the throw and the un-clamped `array_slice` were deletable with the suite green.
     *
     * Unreachable through `calculate()`, and that is proven rather than assumed: every rounding mode returns at
     * least `floor(exact)` and flooring is superadditive, so the group VAT can never be below the sum of the
     * floored shares. Reached here by reflection with a `groupVat` inconsistent with the nets — which is not a
     * contrivance but the standard this file already set for
     * {@see testTheAllocatorSumInvariantHoldsForANegativeGroupVat}. CLAUDE.md § Gotchas: say *not covered, and
     * here is what it would take* — here it takes six lines of the reflection the sibling test already uses.
     */
    public function testTheAllocatorRefusesAGroupVatBelowItsOwnFlooredShares(): void
    {
        $tnd = Currency::of('TND');
        $allocate = new \ReflectionMethod(DocumentCalculator::class, 'allocate');

        $this->expectException(\LogicException::class);
        // The MESSAGE, not just the class: a crash and a detection are indistinguishable otherwise, which this
        // repo has recorded three times.
        $this->expectExceptionMessage('exceed the group VAT');

        $allocate->invoke(
            null,
            // Two lines of 0.013 at 19% floor to 0.002 each, so the shares total 0.004 — more than this.
            Money::of('0.001', $tnd),
            [0, 1],
            [Money::of('0.013', $tnd), Money::of('0.013', $tnd)],
            Rate::fromPercentage('19'),
        );
    }

    /**
     * **The column is ordered NUMERICALLY, which only a document of TEN OR MORE lines can prove.**
     *
     * Round 18's F2. `ksort($vatByLine)` was pinned against deletion and against `asort` by the fixture's
     * interleaved three-line case, but not against `ksort($vatByLine, SORT_STRING)` — with fewer than ten lines,
     * string and numeric key order agree, so the flag is invisible. At ten it diverges (`'10' < '2'`), and a
     * sort-flag change is a PERMUTATION, so no sum-based or count-based assertion can see it either: the total
     * stays bit-identical while a line is told it owes another line's tax. `Invoice::MAX_LINES` is 1000 and a
     * twelve-line invoice is ordinary.
     *
     * Twelve lines with DISTINCT nets, so every share is a different number and a permutation cannot hide behind
     * two equal values. Expected values come from `PriceCalculator` per line rather than being written out: this
     * file must not carry hand-computed money literals, and under `PerLine` each line's share IS its own rounded
     * VAT, so the identity is the assertion.
     */
    public function testThePerLineColumnIsOrderedNumericallyNotAsStrings(): void
    {
        $tnd = Currency::of('TND');
        $prices = new PriceCalculator();
        $rates = [Rate::fromPercentage('19'), Rate::fromPercentage('7')];

        $lines = [];
        $expected = [];

        for ($position = 0; $position < 12; ++$position) {
            // Alternating rates, so the two groups interleave and the group-visit order is not the line order —
            // which is what makes the key ordering observable at all.
            $rate = $rates[$position % 2];
            $net = Money::of(\sprintf('%d.000', $position + 1), $tnd);
            $lines[] = new DocumentLine('1', $net, $rate);
            $expected[] = $prices->vat($net, $rate, RoundingMode::HalfUp)->amount();
        }

        $totals = new DocumentCalculator()->calculate($lines, [], VatRoundingPoint::PerLine, RoundingMode::HalfUp, $tnd);

        self::assertSame($expected, array_map(
            static fn(Money $m): string => $m->amount(),
            $totals->vatByLine(),
        ), 'line 3 must not be shown line 11\'s tax; SORT_STRING orders 10, 11, 12 before 2');
    }

    /**
     * The per-line VAT column matches the SHARED VECTORS — so Angular and Flutter cannot invent their own rule.
     *
     * The three `per-line-vat-allocation-*` cases carry `vat_by_line`. Two of them also carry a
     * `vat_by_line_if_*_which_is_WRONG` field recording what the natural mistake produces, and this docblock used
     * to be the ONLY thing that mentioned them — prose, not a check, so deleting both left the suite green and a
     * wrong value in either was undetectable (round 17's P2-8). They are consumed by
     * {@see testRecomputingThePerLineColumnProducesTheFixturesShortColumn} and
     * {@see testRoundingTheSharesToNearestProducesTheFixturesOverColumn} below. Without a shared vector each tier
     * would recompute `line_net x rate` and get a column that does not sum to the total.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('documentCasesWithAPerLineVatColumn')]
    public function testThePerLineVatColumnMatchesTheSharedVectors(array $case): void
    {
        $totals = self::calculate($case, self::declaredPoint($case));

        self::assertSame(
            $case['vat_by_line'],
            array_map(static fn(Money $m): string => $m->amount(), $totals->vatByLine()),
            'per-line VAT for ' . $case['id'],
        );
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function documentCasesWithAPerLineVatColumn(): array
    {
        return array_filter(
            self::documentCases(),
            static fn(array $case): bool => isset($case[0]['vat_by_line']),
        );
    }

    /**
     * And the fixture must KEEP at least three such cases — one per property the allocator has (tie-break,
     * comparison direction, flooring). Generated cases move with the data, so a deletion is otherwise silent.
     *
     * **The two NEAR-MISS columns are asserted present by NAME here, and that half was missing.** An empty data
     * provider is an error PHPUnit reports against a method name rather than against the fixture, so deleting a
     * `_WRONG` field would read as "this test lost its data" instead of "the shared contract lost the number that
     * pins the wrong answer". Named here, a deletion says which field and which case.
     */
    public function testTheFixtureStillPinsEveryPropertyOfTheAllocation(): void
    {
        // **EACH ALLOCATOR PROPERTY IS PINNED BY NAME. A COUNT FLOOR CANNOT NOTICE A MISSING *PROPERTY*** —
        // it only notices a missing case, and any case will do. This assertion was `assertGreaterThanOrEqual(3,
        // count(...))` until round 6 found it toothless: the same delta that closed R5K-3 added `vat_by_line` to
        // `vat-rounding-order-diverges` and to the new `vat-rounding-order-diverges-per-line`, taking the
        // population from four to six while the floor stayed at three. A guard that had tolerated ONE deletion
        // silently began tolerating THREE, and deleting `per-line-vat-allocation-unequal-remainders` — the only
        // vector in the file whose two lines have unequal remainders, and therefore the only one that can tell
        // largest-remainder from smallest-remainder — left the whole unit suite green.
        //
        // What that costs is not a PHP regression: `testTheRemainderGoesToTheLineWithTheLargestClaim` hard-codes
        // the same two lines. It costs the OTHER TIERS, which is the entire reason this file exists — a client
        // implementing smallest-remainder would pass every committed vector while printing a different per-line
        // VAT column than the API on the same legal document.
        //
        // The two `_WRONG` fields below were already pinned by name, for exactly this argument, in the change
        // that added them. It was not extended to the cases carrying the properties.
        $cases = self::documentCases();

        self::assertArrayHasKey(
            'per-line-vat-allocation-unequal-remainders',
            $cases,
            'THE DIRECTION of the comparison. The only case whose lines have unequal remainders (0.013 floors '
            . 'away 0.00047, 0.021 floors away 0.00099), so it is the only one where reversing the comparator '
            . 'changes an answer. Every other vat_by_line case is a tie, distributes zero units, or is per_line '
            . 'and allocates nothing at all — so without this case a smallest-remainder tier passes them all.',
        );
        self::assertArrayHasKey(
            'per-line-vat-allocation-equal-remainders',
            $cases,
            'THE TIE-BREAK. Equal remainders, so the odd unit is decided by document order alone — the property '
            . 'the ksort() in the allocator exists to make deterministic.',
        );
        self::assertArrayHasKey(
            'per-line-vat-allocation-floors-before-distributing',
            $cases,
            'FLOORING BEFORE DISTRIBUTING. Without it nothing exercises a shortfall large enough for the '
            . 'floor-then-distribute order to differ from rounding each share, which is the step that makes the '
            . 'column sum to the group VAT rather than exceed it.',
        );

        // **THE INTERLEAVED CASE'S OWN `vat_by_line`, asserted by NAME — the whole closure of the document-order
        // finding rested on it and nothing held it there** (round 18's CP-P1). It is the only multi-rate case
        // carrying the column, so deleting that one field makes `ksort($vatByLine)` deletable again with the suite
        // green. No count is written here: this comment used to justify itself with "there are four such cases and
        // it requires three", which was true when written, false by the next commit, and is the reason the floor
        // above is gone — see the argument there. The same change that added this field pinned the two `_WRONG`
        // fields by name for exactly this reason and did not extend the treatment to the field it was adding.
        self::assertArrayHasKey(
            'vat_by_line',
            $cases['multi-rate-vat-breakdown-with-stamp-duty'][0],
            'The ONLY multi-rate per-line column. Without it nothing exercises a document whose rate groups are '
            . 'visited in an order that is not the line order, so a column that misattributes one line\'s tax to '
            . 'another passes every remaining assertion — the total is unchanged by a permutation.',
        );

        // The two DIRECTIONS a wrong per-line column can miss in — short and over — one committed case each. They
        // are separate fields rather than one, because flooring is required by the OVER case alone: a rule that
        // only ever came out short would need no flooring step at all.
        self::assertArrayHasKey(
            'vat_by_line_if_recomputed_which_is_WRONG',
            $cases['per-line-vat-allocation-equal-remainders'][0],
            'The SHORT near miss: the column a tier gets by recomputing line_net x rate. Without it, nothing '
            . 'states what the natural mistake produces and every tier is free to rediscover it.',
        );
        self::assertArrayHasKey(
            'vat_by_line_if_rounded_to_nearest_which_is_WRONG',
            $cases['per-line-vat-allocation-floors-before-distributing'][0],
            'The OVER near miss, which is the one that justifies flooring: rounding each share to nearest gives a '
            . 'column that EXCEEDS the group VAT, and there is then no way to take a millime back from a line '
            . 'without picking a victim.',
        );
    }

    /**
     * **THE SHORT NEAR MISS — the column a tier gets by recomputing, asserted against the fixture's own value.**
     *
     * Round 17's P2-8. `vat_by_line_if_recomputed_which_is_WRONG` was consulted by nothing: it was described in a
     * docblock and read by no assertion, so deleting it left the whole suite green and a wrong number in it was
     * undetectable — while the older sibling beside it (`vat_if_per_line`, named
     * `vat_if_rounded_per_line_which_is_WRONG` until round 5) WAS load-bearing,
     * which is what makes this a gap rather than a property of such fields. CLAUDE.md § Gotchas records the shape:
     * *"a permission that nothing consults permits everything"*, and *"introspection describes a rule, it does not
     * apply one"* — a docblock even less so.
     *
     * **`assertNotSame` alone would be vacuous**, because it passes for every wrong value the field could hold. So
     * the near miss is also asserted EQUAL to what recomputation actually yields, which is `VatRoundingPoint::PerLine`
     * — each line keeping its own `lineNet × rate` rounded at the currency's scale, per
     * {@see testUnderPerLineRoundingEachLineKeepsItsOwnRoundedVat}. That is the same two-armed treatment
     * {@see testPerLineRoundingProducesTheFixturesDivergentValue} gives the VAT *total*, applied to the *column*.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('documentCasesWhoseRecomputedColumnIsShort')]
    public function testRecomputingThePerLineColumnProducesTheFixturesShortColumn(array $case): void
    {
        // ALLOCATION IS A `per_rate_group` PROPERTY ONLY (SPEC § 3): under `per_line` the column IS the
        // rounded per-line figures and there is no shortfall to distribute. Asserted rather than assumed,
        // because the sibling test above was measuring a `per_line` case under this literal and produced the
        // allocated column instead of the per-line one -- silently, until a `per_line` vector existed.
        self::assertSame('per_rate_group', $case['vat_rounding_point'], 'allocation case ' . $case['id']);

        $allocated = self::calculate($case, VatRoundingPoint::PerRateGroup);
        $recomputed = self::calculate($case, VatRoundingPoint::PerLine);
        $wrong = $case['vat_by_line_if_recomputed_which_is_WRONG'];

        self::assertSame(
            $case['vat_by_line'],
            self::amountsOf($allocated->vatByLine()),
            'the ALLOCATED column for ' . $case['id'],
        );
        self::assertNotSame(
            $wrong,
            self::amountsOf($allocated->vatByLine()),
            'The allocator must not produce the recomputed column. If it does, this case pins nothing.',
        );
        self::assertSame(
            $wrong,
            self::amountsOf($recomputed->vatByLine()),
            'The committed WRONG value must be the column recomputation actually yields. A near miss nobody can '
            . 'produce is an invented figure, and a tier comparing against it would be told it is right when it '
            . 'is wrong in some other way.',
        );

        // AND IT IS SHORT — the defect this field exists to record. Asserted against the kernel's own total rather
        // than against the fixture's `vat` string, so this is a statement about behaviour and not about JSON.
        $currency = Currency::of($case['currency']);
        self::assertTrue(
            self::sumOf($wrong, $currency)->isLessThan($allocated->vatTotal()),
            'The recomputed column must fall SHORT of the group VAT — that shortfall is the millime nobody owns '
            . 'and the entire reason the figure is allocated rather than recomputed.',
        );
    }

    /**
     * **THE OVER NEAR MISS — the second DIRECTION a wrong column can miss in, not a second defect.**
     *
     * The wording matters and the earlier version had it wrong (round 18's C-F5): both near-miss columns come from
     * the SAME computation — each line keeping its own rounded VAT — so they are one rule observed twice, once
     * where it undershoots and once where it overshoots. Two fields rather than one because the DIRECTIONS carry
     * different weight, not because the rules differ: only the over case justifies flooring.
     *
     * Same P2-8 gap, same treatment. What this one adds: rounding each exact share to NEAREST instead of flooring
     * makes the column EXCEED the group VAT, and a column that is over cannot be corrected without taking a millime
     * back from a line — choosing a victim, which is the arbitrariness largest-remainder exists to avoid. A rule
     * that only ever came out short (the case above) would need no flooring step at all, so deleting this field
     * would leave `allocate()`'s `RoundingMode::Floor` argued for by nothing in the shared contract.
     *
     * The same three `0.008 TND` lines at 19% are what make the direction observable: an exact share of `0.00152`
     * rounds to `0.002` and three of them sum to `0.006` against a group VAT of `0.005`.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('documentCasesWhoseRoundedToNearestColumnIsOver')]
    public function testRoundingTheSharesToNearestProducesTheFixturesOverColumn(array $case): void
    {
        // ALLOCATION IS A `per_rate_group` PROPERTY ONLY (SPEC § 3): under `per_line` the column IS the
        // rounded per-line figures and there is no shortfall to distribute. Asserted rather than assumed,
        // because the sibling test above was measuring a `per_line` case under this literal and produced the
        // allocated column instead of the per-line one -- silently, until a `per_line` vector existed.
        self::assertSame('per_rate_group', $case['vat_rounding_point'], 'allocation case ' . $case['id']);

        $allocated = self::calculate($case, VatRoundingPoint::PerRateGroup);
        $toNearest = self::calculate($case, VatRoundingPoint::PerLine);
        $wrong = $case['vat_by_line_if_rounded_to_nearest_which_is_WRONG'];

        self::assertSame(
            $case['vat_by_line'],
            self::amountsOf($allocated->vatByLine()),
            'the ALLOCATED column for ' . $case['id'],
        );
        self::assertNotSame(
            $wrong,
            self::amountsOf($allocated->vatByLine()),
            'Flooring is what stops the allocator producing this column. If it produces it anyway, the shares were '
            . 'rounded to nearest and the column exceeds the tax actually owed.',
        );
        // `HalfUp` at the currency's scale IS rounding the exact share to nearest, so the fixture's number is
        // reachable rather than asserted into existence — the same argument as the SHORT case above.
        self::assertSame(
            $wrong,
            self::amountsOf($toNearest->vatByLine()),
            'The committed WRONG value must be the column rounding-to-nearest actually yields.',
        );

        $currency = Currency::of($case['currency']);
        self::assertTrue(
            self::sumOf($wrong, $currency)->isGreaterThan($allocated->vatTotal()),
            'Rounding to nearest must OVERSHOOT the group VAT. If it merely fell short, flooring would be an '
            . 'arbitrary choice instead of the only order that keeps the column correctable.',
        );
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function documentCasesWhoseRecomputedColumnIsShort(): array
    {
        return array_filter(
            self::documentCases(),
            static fn(array $case): bool => isset($case[0]['vat_by_line_if_recomputed_which_is_WRONG']),
        );
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function documentCasesWhoseRoundedToNearestColumnIsOver(): array
    {
        return array_filter(
            self::documentCases(),
            static fn(array $case): bool => isset($case[0]['vat_by_line_if_rounded_to_nearest_which_is_WRONG']),
        );
    }

    /**
     * @param list<Money> $amounts
     *
     * @return list<string>
     */
    private static function amountsOf(array $amounts): array
    {
        return array_map(static fn(Money $amount): string => $amount->amount(), $amounts);
    }

    /**
     * A committed column of decimal STRINGS summed as money, so a near miss is compared to the group figure with
     * the same exact arithmetic the kernel uses rather than with a float.
     *
     * @param list<string> $amounts
     */
    private static function sumOf(array $amounts, Currency $currency): Money
    {
        $sum = Money::zero($currency);

        foreach ($amounts as $amount) {
            $sum = $sum->plus(Money::of($amount, $currency));
        }

        return $sum;
    }

    /**
     * A document is bounded in the NUMBER of lines and charges it may hold, and the bound has a committed value.
     *
     * Round 16 filed `document_line.position smallint` as specified with no constant behind it — the same
     * domain-admits-what-persistence-rejects mismatch the `document.number` and `NumberPattern::MAX_WIDTH` fixes
     * closed. Nothing bounded the count at all, so a document could hold more lines than the column could address.
     */
    public function testADocumentIsBoundedInTheNumberOfLinesItHolds(): void
    {
        self::assertSame(1000, Invoice::MAX_LINES, 'The smallint position column is sized from this; change both.');

        $tnd = Currency::of('TND');
        $line = new DocumentLine('1', Money::of('0.001', $tnd), Rate::zero());
        $invoice = Invoice::draft($tnd)->withClient(self::FIXTURE_CLIENT);

        for ($i = 0; $i < Invoice::MAX_LINES; ++$i) {
            $invoice = $invoice->withLine($line);
        }

        // The boundary is ACCEPTED — without this half, `<` and `<=` are indistinguishable and the off-by-one
        // would refuse a document one line short of the limit.
        self::assertCount(Invoice::MAX_LINES, $invoice->lines());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('which is the maximum');

        $invoice->withLine($line);
    }

    /**
     * **Under `PerLine`, a line's VAT share is its OWN rounded figure — nothing is allocated.**
     *
     * Round 17's F1, and it was a real wrong number in a legal document rather than a style point. `allocate()`
     * declared a `RoundingMode` parameter and never read it, flooring with `Down` whatever the caller asked for,
     * and it was applied under BOTH rounding points. Under `PerLine` that is simply invalid: the group's VAT is
     * DEFINED as the sum of the per-line rounded figures, so they add up to it exactly and any redistribution
     * moves tax onto a line that does not owe it.
     *
     * The distinguishing input needs a mode whose result differs from flooring, which is why this is `HalfEven`
     * and why the earlier tests could not see it: every one of them used `PerRateGroup`.
     */
    public function testUnderPerLineRoundingEachLineKeepsItsOwnRoundedVat(): void
    {
        $tnd = Currency::of('TND');
        $lines = [
            // 0.150 x 19% = 0.02850 exactly -- a HALF, so the mode decides: HalfEven gives 0.028.
            new DocumentLine('1', Money::of('0.150', $tnd), Rate::fromPercentage('19')),
            // 0.050 x 19% = 0.00950 exactly -- also a half; HalfEven gives 0.010.
            new DocumentLine('1', Money::of('0.050', $tnd), Rate::fromPercentage('19')),
        ];

        $totals = new DocumentCalculator()->calculate(
            $lines,
            [],
            VatRoundingPoint::PerLine,
            RoundingMode::HalfEven,
            $tnd,
        );

        self::assertSame(['0.028', '0.010'], array_map(
            static fn(Money $m): string => $m->amount(),
            $totals->vatByLine(),
        ), 'each line keeps the figure PriceCalculator::vat() gave it; allocation reported 0.029 and 0.009');

        // And the sum still holds, which is what makes allocating unnecessary rather than merely wrong here.
        self::assertSame('0.038', $totals->vatTotal()->amount());
    }

    /**
     * **And the column does not depend on the ORDER the lines were entered in.**
     *
     * Separate from the assertion above because it fails for a different reason: allocation handed the remainder
     * to whichever line had the largest floored-away part, so swapping two lines moved the millime. A tax figure
     * that changes when a user drags a row is a defect no total-based assertion can see.
     */
    public function testUnderPerLineRoundingTheColumnDoesNotDependOnLineOrder(): void
    {
        $tnd = Currency::of('TND');
        $big = new DocumentLine('1', Money::of('0.150', $tnd), Rate::fromPercentage('19'));
        $small = new DocumentLine('1', Money::of('0.050', $tnd), Rate::fromPercentage('19'));

        $forwards = new DocumentCalculator()->calculate(
            [$big, $small],
            [],
            VatRoundingPoint::PerLine,
            RoundingMode::HalfEven,
            $tnd,
        );
        $backwards = new DocumentCalculator()->calculate(
            [$small, $big],
            [],
            VatRoundingPoint::PerLine,
            RoundingMode::HalfEven,
            $tnd,
        );

        $amounts = static fn(DocumentTotals $t): array => array_map(
            static fn(Money $m): string => $m->amount(),
            $t->vatByLine(),
        );

        self::assertSame(['0.028', '0.010'], $amounts($forwards));
        self::assertSame(
            array_reverse($amounts($forwards)),
            $amounts($backwards),
            'reversing the lines must reverse the column and change nothing else',
        );
    }

    /**
     * **The allocator's sum invariant holds for a NEGATIVE group VAT too, which is why it floors rather than
     * truncating.**
     *
     * Round 17's F3. `RoundingMode::Down` truncates toward zero, so for negative amounts it rounds UP and the
     * floored shares overshoot the group figure — the shortfall goes negative and a `max(0, $units)` clamp
     * silently absorbed it, leaving a column that does not sum to the total. `Floor` is correct for either sign.
     *
     * Reached by reflection deliberately: `DocumentLine` refuses a negative quantity, unit price and rate, so
     * there is no public path to it TODAY. Wave 2's credit note is named in `DocumentCalculator`'s own comments,
     * and CLAUDE.md § Gotchas records four cases this session where an untested branch was assumed unreachable
     * and was not — so the branch is pinned before it acquires a caller, not after.
     */
    public function testTheAllocatorSumInvariantHoldsForANegativeGroupVat(): void
    {
        $tnd = Currency::of('TND');
        $allocate = new \ReflectionMethod(DocumentCalculator::class, 'allocate');

        $shares = $allocate->invoke(
            null,
            Money::of('-0.005', $tnd),
            [0, 1],
            [Money::of('-0.013', $tnd), Money::of('-0.013', $tnd)],
            Rate::fromPercentage('19'),
        );

        $sum = Money::zero($tnd);

        foreach ($shares as $share) {
            $sum = $sum->plus($share);
        }

        self::assertSame(
            '-0.005',
            $sum->amount(),
            'Truncating toward zero gives -0.004 -- a credit note whose line column under-refunds by a millime.',
        );
        self::assertSame(['-0.002', '-0.003'], array_map(
            static fn(Money $m): string => $m->amount(),
            $shares,
        ), 'the extra millime goes to the earliest line on a tie, exactly as for positive amounts');
    }

    /**
     * `PerRateGroup` with `RoundingMode::Unnecessary` totals a document whose GROUP VAT is exactly representable.
     *
     * **ROUND 4 FILED THIS (R4C-6) AND IT IS A REAL REFUSAL OF A CORRECT DOCUMENT.** Two TND lines of `0.001` at
     * 50 % have a group base of `0.002` and a group VAT of `0.001` — exact in a three-decimal currency, nothing to
     * round. It was refused anyway, with `Result "0.000500000000000" does not fit TND (3 decimal place(s)) and
     * RoundingMode::Unnecessary forbids rounding it`.
     *
     * The `0.0005` is each line's own `net x rate`, and under `PerRateGroup` **that figure is discarded**: the
     * per-line shares come from `allocate()`, which divides the already-rounded group VAT by largest remainder.
     * So the document was refused because a value it never uses could not be represented.
     *
     * The comment on the computation defended doing it unconditionally — *"branching here means the two arms
     * exercise different code and the cheap one rots"* — which is a reasonable instinct about code health and the
     * wrong trade against a wrong answer. It also does not apply: both rounding points are driven by the shared
     * vectors, so neither arm is at risk of going unexercised.
     *
     * Not reachable from today's transport, which passes `HalfUp`. `totals()` is public and its signature
     * documents the mode as the caller's, which is what makes this the aggregate's contract rather than an
     * internal detail.
     */
    public function testPerRateGroupWithUnnecessaryRoundingTotalsAnExactlyRepresentableGroupVat(): void
    {
        $tnd = Currency::of('TND');
        $invoice = Invoice::draft($tnd)
            ->withLine(new DocumentLine('1', Money::of('0.001', $tnd), Rate::fromPercentage('50')))
            ->withLine(new DocumentLine('1', Money::of('0.001', $tnd), Rate::fromPercentage('50')));

        $totals = $invoice->totals(VatRoundingPoint::PerRateGroup, RoundingMode::Unnecessary);

        self::assertSame('0.002', $totals->subtotalNet()->amount());
        self::assertSame('0.001', $totals->vatTotal()->amount(), 'the group VAT is exact: 0.002 x 50% = 0.001');
        self::assertSame('0.003', $totals->total()->amount());

        // AND THE ALLOCATION STILL SUMS EXACTLY TO THE GROUP VAT, which is the invariant the whole allocator
        // exists for. One line gets the millime, the other gets nothing, earliest-line-on-a-tie.
        $shares = array_map(static fn(Money $m): string => $m->amount(), $totals->vatByLine());
        self::assertSame(['0.001', '0.000'], $shares);
    }

    /**
     * And `PerLine` with `Unnecessary` STILL refuses the same document — because there the figure is the answer.
     *
     * The companion to the case above, and the reason the fix is a branch rather than a softer rounding mode.
     * Under `PerLine` each line's own VAT IS its share and IS what the group total is summed from, so `0.0005` in
     * a three-decimal currency is a figure the document genuinely cannot state. Refusing is correct, and a fix
     * that made this pass would have been the bandaid.
     */
    public function testPerLineWithUnnecessaryRoundingStillRefusesAnUnrepresentableLineVat(): void
    {
        $tnd = Currency::of('TND');
        $invoice = Invoice::draft($tnd)
            ->withLine(new DocumentLine('1', Money::of('0.001', $tnd), Rate::fromPercentage('50')))
            ->withLine(new DocumentLine('1', Money::of('0.001', $tnd), Rate::fromPercentage('50')));

        $this->expectException(InvalidMoneyAmount::class);
        $this->expectExceptionMessage('forbids rounding it');

        $invoice->totals(VatRoundingPoint::PerLine, RoundingMode::Unnecessary);
    }
}
