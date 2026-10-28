<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
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
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Fiscal\Domain\ValueObject\TaxKind;
use App\Module\Products\Domain\Exception\InvalidProduct;
use App\Module\Products\Domain\Model\Product;
use App\Tenancy\Domain\Model\Company;
use BcMath\Number;
use Symfony\Component\Uid\Uuid;

/**
 * What a customer pays for a product, taxes included (docs/SPEC.md § 7, 2026-09-23 slice 6, the price check): the
 * quantity on one line of a document at its net price, with the line taxes the product starts a line with, counted by
 * the calculator every document uses, at the company's currency scale and VAT rounding. So a pack is priced as twelve
 * units on one line, exactly as the invoice for it would be, and a tax entering the VAT base (FODEC) is counted there.
 * No document tax (a stamp) and no customer's regime: this is the shelf price, for anyone. The company's own regime does
 * apply, since it decides what every one of its documents charges: a « forfaitaire » company's VAT is never counted, so a
 * price it wrote taxes included is the price it charges. A price other than the shelf's (a promotion's) is counted the
 * same way when `$unitPriceNet` names it.
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
        private ExcludedTaxFamilies $excluded,
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
        try {
            $taxes = $this->lineTaxes($company, $product->getDefaultTaxComponentIds());
        } catch (InvalidProduct $broken) {
            throw new \LogicException(\sprintf('The product %s starts its lines with a tax that is not a line tax of its company.', $product->getReference()), 0, $broken);
        }

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
        $taxes = $this->lineTaxes($company, array_values(array_unique($taxComponentIds)));

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
        $taxes = $this->lineTaxes($company, array_values(array_unique($taxComponentIds)));
        // A net price counted in ten-thousandths, the scale a price is kept at.
        $total = fn (int $net): Number => Decimal::of($this->counted($company, '1', self::price($net), $taxes)->total);
        $target = Decimal::of($gross);
        // What a unit's taxes multiply its price by, read on a price large enough for the rounding to vanish.
        $factor = $total(self::LARGE)->div(intdiv(self::LARGE, self::UNIT), Decimal::WORKING_SCALE);
        $exact = max(0, (int) Decimal::format($target->div($factor, Decimal::WORKING_SCALE)->mul(self::UNIT), 0));

        // The total never falls as the net price rises, so the net prices coming to the figure are one run, and the one
        // nearest the exact quotient is the quotient itself when it comes to the figure, else the first of the run above
        // it or the last below it. Each is looked for from the quotient outward, a few prices away, rather than over
        // every price from nothing: a products file counts one per row.
        $at = $total($exact)->compare($target);
        if (0 === $at) {
            return self::price($exact);
        }
        if ($at < 0) {
            $first = self::beside($total, $exact, 1, static fn (Number $sum): bool => $sum->compare($target) >= 0);
            if (null === $first || 0 !== $total($first)->compare($target)) {
                throw $this->unreachable($company, $gross, $first ?? 0, $taxes);
            }

            return self::price($first);
        }
        $last = self::beside($total, $exact, -1, static fn (Number $sum): bool => $sum->compare($target) <= 0);
        if (null === $last || 0 !== $total($last)->compare($target)) {
            // No net price comes to it: the first above it is the one after the last below, or nothing at all.
            throw $this->unreachable($company, $gross, null === $last ? 0 : $last + 1, $taxes);
        }

        return self::price($last);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<TaxInput>
     *
     * @throws InvalidProduct when one of them is not a line tax of the company
     */
    private function lineTaxes(Company $company, array $ids): array
    {
        $taxes = [];
        $excluded = $this->excluded->companyRegime($company)->excludedFamilies ?? [];
        foreach ($ids as $id) {
            $tax = Uuid::isValid($id) ? $this->taxes->ofIdInCompany(Uuid::fromString($id), $company->getId()) : null;
            // Refused as the product's own form refuses its line taxes, so a screen or an import says which.
            if (null === $tax) {
                throw new InvalidProduct('defaultTaxComponentIds', \sprintf('No tax of this company has the id %s.', $id), 'unknown_tax');
            }
            $rate = $tax->getRate();
            if (TaxKind::PercentageLine !== $tax->getKind() || null === $rate) {
                throw new InvalidProduct('defaultTaxComponentIds', \sprintf('%s is charged on a document, not on a line.', $tax->getCode()), 'tax_on_document', ['tax' => $tax->getName()]);
            }
            // Still one of its line taxes, so a product may name it, but its documents never charge it.
            if (\in_array($tax->getFamily(), $excluded, true)) {
                continue;
            }
            $taxes[] = TaxInput::percentage($tax->getCode(), Rate::fromPercentage($rate), $tax->entersVatBase());
        }

        return $taxes;
    }

    /**
     * The net price nearest `$from`, which fails, in `$direction` whose total passes: the first above it, or the last
     * below it, null when none at or above nothing does. Steps double away from it until a price passes, then halve
     * back to the one beside the last that fails; every price beyond a passing one passes too, the total never falling
     * as the net price rises.
     *
     * @param \Closure(int): Number  $total
     * @param \Closure(Number): bool $passes
     */
    private static function beside(\Closure $total, int $from, int $direction, \Closure $passes): ?int
    {
        $failing = $from;
        for ($step = 1;; $step *= 2) {
            $passing = max(0, $from + $direction * $step);
            if ($passes($total($passing))) {
                break;
            }
            if (0 === $passing) {
                return null;
            }
            $failing = $passing;
        }
        while (abs($passing - $failing) > 1) {
            $middle = intdiv($passing + $failing, 2);
            if ($passes($total($middle))) {
                $passing = $middle;
            } else {
                $failing = $middle;
            }
        }

        return $passing;
    }

    /**
     * No net price comes to the figure: the totals of the two around it, the last below and the first above.
     *
     * @param list<TaxInput> $taxes
     */
    private function unreachable(Company $company, string $gross, int $first, array $taxes): GrossUnreachable
    {
        return new GrossUnreachable($gross, $this->counted($company, '1', self::price($first - 1), $taxes)->total, $this->counted($company, '1', self::price($first), $taxes)->total);
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
