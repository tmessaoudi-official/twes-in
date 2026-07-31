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
use Twes\Domain\Document\VatRoundingPoint;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Shared\RoundingMode;

/**
 * The calculation kernel, driven ENTIRELY by the committed cross-tier vectors.
 *
 * Not one expected number in this file is written by hand. Every one is read from
 * `docs/spec/pricing-vectors.json`, because that file is the contract three implementations (PHP,
 * TypeScript, Dart) must agree on, and a test asserting a locally-invented figure would let this tier
 * drift from the other two while staying green — which is the exact failure the fixture exists to prevent.
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
}
