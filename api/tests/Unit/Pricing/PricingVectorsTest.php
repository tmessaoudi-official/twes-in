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
        self::assertGreaterThanOrEqual(9, \count($vectors['cases']));
        self::assertGreaterThanOrEqual(8, \count($vectors['edit_directions']));
        self::assertGreaterThanOrEqual(1, \count($vectors['document_totals']));
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

        if (null !== $expectedNet) {
            self::assertNotNull($profitRate, "case {$id} must supply a profit_rate");

            $net = $calculator->netFromCost(
                $costMoney,
                Rate::fromPercentage($profitRate),
                RoundingMode::HalfUp,
            );

            self::assertSame($expectedNet, $net->amount(), "net_price, case {$id} (edited {$editedField})");

            return;
        }

        self::assertNotNull($netPrice, "case {$id} must supply a net_price");

        $rate = $calculator->profitRateFromNet(
            $costMoney,
            Money::of($netPrice, $currencyObject),
            RoundingMode::HalfUp,
        );

        if ($rateIsExpectedToBeUndefined) {
            self::assertNull($rate, "case {$id}: a zero cost leaves the rate undefined, not zero");

            return;
        }

        self::assertNotNull($rate, "case {$id}");
        self::assertSame($expectedRate, $rate->percentage(), "profit_rate, case {$id}");
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
     * The end-to-end document case: line totals, a VAT rate over the subtotal, and Tunisia's fixed
     * stamp duty — which is a document-scope charge in the generic charge model, never a special case
     * in code, and unrepresentable in a two-decimal currency.
     */
    #[DataProvider('documentTotalCases')]
    public function testDocumentTotalsMatchTheSharedVectors(
        string $id,
        string $currency,
        array $lines,
        string $expectedSubtotal,
        string $vatRate,
        string $expectedVat,
        array $fixedCharges,
        string $expectedTotal,
    ): void {
        $currencyObject = Currency::of($currency);
        $calculator = new PriceCalculator();

        $subtotal = Money::zero($currencyObject);

        foreach ($lines as $line) {
            $lineNet = Money::of($line['unit_net'], $currencyObject)
                ->multipliedBy($line['quantity'], RoundingMode::HalfUp);

            self::assertSame($line['line_net'], $lineNet->amount(), "line net, case {$id}");

            $subtotal = $subtotal->plus($lineNet);
        }

        self::assertSame($expectedSubtotal, $subtotal->amount(), "subtotal, case {$id}");

        $vat = $calculator->vat($subtotal, Rate::fromPercentage($vatRate), RoundingMode::HalfUp);
        self::assertSame($expectedVat, $vat->amount(), "vat, case {$id}");

        $total = $subtotal->plus($vat);

        foreach ($fixedCharges as $charge) {
            $total = $total->plus(Money::of($charge['amount'], $currencyObject));
        }

        self::assertSame($expectedTotal, $total->amount(), "total, case {$id}");
    }

    /** @return iterable<string, array{string, string, array, string, string, string, array, string}> */
    public static function documentTotalCases(): iterable
    {
        foreach (self::vectors()['document_totals'] as $case) {
            yield $case['id'] => [
                $case['id'],
                $case['currency'],
                $case['lines'],
                $case['subtotal_net'],
                $case['vat_rate'],
                $case['vat'],
                $case['fixed_charges'],
                $case['expected']['total'],
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
