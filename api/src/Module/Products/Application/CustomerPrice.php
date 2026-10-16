<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Calculation\DocumentCalculator;
use App\Fiscal\Domain\Calculation\DocumentInput;
use App\Fiscal\Domain\Calculation\DocumentTotals;
use App\Fiscal\Domain\Calculation\InvalidDocument;
use App\Fiscal\Domain\Calculation\LineInput;
use App\Fiscal\Domain\Calculation\Rate;
use App\Fiscal\Domain\Calculation\TaxBasis;
use App\Fiscal\Domain\Calculation\TaxInput;
use App\Fiscal\Domain\Calculation\UnsupportedTaxCombination;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\TaxKind;
use App\Module\Products\Domain\InvalidProduct;
use App\Module\Products\Domain\Product;
use App\Tenancy\Domain\Company;
use BcMath\Number;
use Symfony\Component\Uid\Uuid;

/**
 * What a customer pays for a product, taxes included (docs/SPEC.md § 7, 2026-09-23 slice 6, the price check): the
 * quantity on one line of a document at its net price, with the line taxes the product starts a line with, counted by
 * the calculator every document uses, at the company's currency scale and VAT rounding. So a pack is priced as twelve
 * units on one line, exactly as the invoice for it would be, and a tax entering the VAT base (FODEC) is counted there.
 * No document tax (a stamp) and no customer's regime: this is the shelf price, for anyone. A price other than the shelf's
 * (a promotion's) is counted the same way when `$unitPriceNet` names it.
 */
final readonly class CustomerPrice
{
    /** A price is kept in ten-thousandths of the currency. */
    private const int UNIT = 10_000;

    /** A net price of a million, in ten-thousandths: large enough for a unit's rounding to vanish from its total. */
    private const int LARGE = 10_000_000_000;

    public function __construct(
        private TaxComponentRepository $taxes,
        private FiscalPresets $presets,
        private CurrencyScales $scales,
    ) {
    }

    /**
     * @throws InvalidDocument           when the price cannot be totalled
     * @throws UnsupportedTaxCombination when the product's taxes combine in a way the calculator does not count
     */
    public function of(Product $product, int $quantity, ?string $unitPriceNet = null): string
    {
        $company = $product->getCompany();
        // ManageProducts keeps only the company's line taxes here, so anything else is a broken invariant, and a price
        // counted without one of its taxes would be a wrong price shown to a customer.
        $taxes = $this->lineTaxes($company, $product->getDefaultTaxComponentIds())
            ?? throw new \LogicException(\sprintf('The product %s starts its lines with a tax that is not a line tax of its company.', $product->getReference()));

        return $this->counted($company, (string) $quantity, $unitPriceNet ?? $product->getDetails()->unitPriceNet, $taxes)->total;
    }

    /**
     * What a price being typed comes to with the line taxes being chosen, one line per quantity asked (a unit, a
     * pack), before the product is saved: the price calculator's with-tax figures, counted here so the browser never
     * guesses a compounding or a rounding.
     *
     * @param list<string> $taxComponentIds
     * @param list<string> $quantities
     *
     * @return list<PricePreview>
     *
     * @throws InvalidProduct            when a tax is not one of the company's line taxes
     * @throws InvalidDocument           when the price cannot be totalled
     * @throws UnsupportedTaxCombination when the taxes combine in a way the calculator does not count
     */
    public function preview(Company $company, array $taxComponentIds, string $unitPriceNet, array $quantities): array
    {
        $taxes = $this->lineTaxes($company, array_values(array_unique($taxComponentIds)))
            ?? throw new InvalidProduct('defaultTaxComponentIds', 'A product starts its lines with its company\'s line taxes only.');

        return array_map(function (string $quantity) use ($company, $unitPriceNet, $taxes): PricePreview {
            $totals = $this->counted($company, $quantity, $unitPriceNet, $taxes);

            return new PricePreview($quantity, $totals->subtotalNet, $totals->totalTax, $totals->total);
        }, $quantities);
    }

    /**
     * The net price at which one unit comes, with these line taxes, to exactly the price a person wrote taxes included
     * (F26: a counter thinks in its shelf price). Counted forward by the calculator every document uses, so the product
     * then sells at that very figure; among the net prices that do, the one nearest the exact quotient, which keeps a
     * pack's price nearest that many units'.
     *
     * @param list<string> $taxComponentIds
     *
     * @throws InvalidProduct            when a tax is not one of the company's line taxes
     * @throws GrossUnreachable          when no net price comes to it, the currency's last digit falling between two
     * @throws InvalidDocument           when the price cannot be totalled
     * @throws UnsupportedTaxCombination when the taxes combine in a way the calculator does not count
     */
    public function netOf(Company $company, array $taxComponentIds, string $gross): string
    {
        $taxes = $this->lineTaxes($company, array_values(array_unique($taxComponentIds)))
            ?? throw new InvalidProduct('defaultTaxComponentIds', 'A product starts its lines with its company\'s line taxes only.');
        // A net price counted in ten-thousandths, the scale a price is kept at.
        $total = fn (int $net): Number => Decimal::of($this->counted($company, '1', self::price($net), $taxes)->total);
        $target = Decimal::of($gross);

        // The total never falls as the net price rises, so the net prices coming to the figure are one run.
        $first = self::first($total, static fn (Number $sum): bool => $sum->compare($target) >= 0);
        if (0 !== $total($first)->compare($target)) {
            throw new GrossUnreachable($gross, $this->counted($company, '1', self::price($first - 1), $taxes)->total, $this->counted($company, '1', self::price($first), $taxes)->total);
        }
        $last = self::first($total, static fn (Number $sum): bool => $sum->compare($target) > 0) - 1;
        // What a unit's taxes multiply its price by, read on a price large enough for the rounding to vanish.
        $factor = $total(self::LARGE)->div(intdiv(self::LARGE, self::UNIT), Decimal::WORKING_SCALE);
        $exact = (int) Decimal::format($target->div($factor, Decimal::WORKING_SCALE)->mul(self::UNIT), 0);

        return self::price(max($first, min($last, $exact)));
    }

    /**
     * @param list<string> $ids
     *
     * @return list<TaxInput>|null null when one of them is not a line tax of the company
     */
    private function lineTaxes(Company $company, array $ids): ?array
    {
        $taxes = [];
        foreach ($ids as $id) {
            $tax = Uuid::isValid($id) ? $this->taxes->ofIdInCompany(Uuid::fromString($id), $company->getId()) : null;
            $rate = $tax?->getRate();
            if (null === $tax || TaxKind::PercentageLine !== $tax->getKind() || null === $rate) {
                return null;
            }
            $taxes[] = TaxInput::percentage($tax->getCode(), Rate::fromPercentage($rate), $tax->entersVatBase());
        }

        return $taxes;
    }

    /**
     * The smallest net price, in ten-thousandths, whose total passes; every larger one passes too, as the total never
     * falls when the net price rises.
     *
     * @param \Closure(int): Number  $total
     * @param \Closure(Number): bool $passes
     */
    private static function first(\Closure $total, \Closure $passes): int
    {
        if ($passes($total(0))) {
            return 0;
        }
        [$low, $high] = [0, 1];
        while (!$passes($total($high))) {
            [$low, $high] = [$high, $high * 2];
        }
        while ($high - $low > 1) {
            $middle = intdiv($low + $high, 2);
            if ($passes($total($middle))) {
                $high = $middle;
            } else {
                $low = $middle;
            }
        }

        return $high;
    }

    private static function price(int $tenThousandths): string
    {
        return \sprintf('%d.%04d', intdiv($tenThousandths, self::UNIT), $tenThousandths % self::UNIT);
    }

    /** @param list<TaxInput> $taxes */
    private function counted(Company $company, string $quantity, string $unitPriceNet, array $taxes): DocumentTotals
    {
        return new DocumentCalculator()->calculate(new DocumentInput(
            $this->scales->of($company->getCurrency()),
            false,
            TaxBasis::Exclusive,
            $this->presets->get($company->getFiscalPreset())->vatRoundingPoint,
            [new LineInput($quantity, $unitPriceNet, null, $taxes)],
        ));
    }
}
