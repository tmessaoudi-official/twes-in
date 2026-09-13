<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

use App\Fiscal\Domain\TaxKind;
use BcMath\Number;

/**
 * Totals a document: line amounts and discounts, the document discount, every percentage tax at the document's
 * rounding point, fixed charges, withholdings and the amount due. The rules, each pinned by a case, are in
 * docs/spec/pricing-vectors.json; the sign of a credit note is applied once, here at the entry.
 */
final class DocumentCalculator
{
    public function calculate(DocumentInput $document): DocumentTotals
    {
        $this->guard($document);
        $scale = $document->scale;
        $sign = $document->credit ? -1 : 1;

        $amounts = [];
        $discounts = [];
        $typed = [];
        foreach ($document->lines as $line) {
            $amount = Decimal::round(Decimal::of($line->quantity)->mul(Decimal::of($line->unitPrice), Decimal::WORKING_SCALE)->mul($sign), $scale);
            $discount = null === $line->discountRate
                ? Decimal::zero()
                : Decimal::round($amount->mul(Rate::fromPercentage($line->discountRate)->fraction(), Decimal::WORKING_SCALE), $scale);
            $amounts[] = $amount;
            $discounts[] = $discount;
            $typed[] = $amount->sub($discount);
        }

        $documentDiscount = null === $document->documentDiscount ? Decimal::zero() : Decimal::of($document->documentDiscount)->mul($sign);
        $shares = $this->allocateDocumentDiscount($document->lines, $typed, $documentDiscount, $scale);
        $discounted = [];
        foreach ($typed as $i => $value) {
            $discounted[] = $value->sub($shares[$i]);
        }

        $inclusive = TaxBasis::Inclusive === $document->basis;
        [$nets, $lineTaxes, $groups] = $inclusive
            ? $this->extract($document, $discounted, $scale)
            : $this->add($document, $discounted, $scale);

        $lines = [];
        foreach ($document->lines as $i => $line) {
            $lines[] = new LineTotals(
                Decimal::format($amounts[$i], $scale),
                Decimal::format($discounts[$i], $scale),
                Decimal::format($inclusive ? $nets[$i] : $typed[$i], $scale),
                $inclusive ? Decimal::format($typed[$i], $scale) : null,
                Decimal::format($shares[$i], $scale),
                array_map(
                    static fn (array $tax) => new LineTax($tax[0], Decimal::format($tax[1], $scale), Decimal::format($tax[2], $scale)),
                    $lineTaxes[$i],
                ),
            );
        }

        $taxes = [];
        $totalTax = Decimal::zero();
        foreach ($groups as [$tax, $base, $amount, $carriers]) {
            \assert(null !== $tax->rate);
            $taxes[] = new TaxTotal(
                $tax->code,
                $tax->rate,
                Decimal::format($base, $scale),
                Decimal::format(Decimal::sum(array_map(static fn (int $i) => $shares[$i], $carriers)), $scale),
                Decimal::format($amount, $scale),
            );
            $totalTax = $totalTax->add($amount);
        }

        $subtotalNet = $inclusive ? Decimal::sum($nets) : Decimal::sum($typed);
        $netAfterDiscount = $inclusive ? $subtotalNet : $subtotalNet->sub($documentDiscount);
        $beforeCharges = $inclusive ? Decimal::sum($discounted) : $netAfterDiscount->add($totalTax);

        $charges = [];
        $total = $beforeCharges;
        $withholdings = [];
        $withheld = Decimal::zero();
        foreach ($document->documentTaxes as $tax) {
            if (TaxKind::FixedDocument === $tax->kind) {
                $amount = Decimal::of((string) $tax->amount)->mul($sign);
                $charges[] = new ChargeTotal($tax->code, Decimal::format($amount, $scale));
                $total = $total->add($amount);
                continue;
            }
            \assert(null !== $tax->rate && null !== $tax->threshold);
            // The base is the total of the lines and their percentage taxes; fixed charges are outside it.
            if (Decimal::absolute($beforeCharges)->compare(Decimal::of($tax->threshold)) < 0) {
                continue;
            }
            $amount = Decimal::round($beforeCharges->mul($tax->rate->fraction(), Decimal::WORKING_SCALE), $scale);
            $withholdings[] = new TaxTotal($tax->code, $tax->rate, Decimal::format($beforeCharges, $scale), Decimal::format(Decimal::zero(), $scale), Decimal::format($amount, $scale));
            $withheld = $withheld->add($amount);
        }

        return new DocumentTotals(
            $lines,
            Decimal::format($subtotalNet, $scale),
            $inclusive ? Decimal::format(Decimal::sum($typed), $scale) : null,
            Decimal::format($documentDiscount, $scale),
            Decimal::format($netAfterDiscount, $scale),
            $taxes,
            Decimal::format($totalTax, $scale),
            $charges,
            Decimal::format($total, $scale),
            $withholdings,
            Decimal::format($total->sub($withheld), $scale),
        );
    }

    /**
     * Two levels, one rule: across the groups of lines carrying the same set of taxes, pro rata by base, then
     * across each group's lines. Largest remainder at both levels, ties to the earliest.
     *
     * @param list<LineInput> $lines
     * @param list<Number>    $bases
     *
     * @return list<Number>
     */
    private function allocateDocumentDiscount(array $lines, array $bases, Number $discount, int $scale): array
    {
        if (0 === $discount->compare(0)) {
            return array_map(static fn () => Decimal::zero(), $bases);
        }
        $total = Decimal::sum($bases);
        if (Decimal::absolute($discount)->compare(Decimal::absolute($total)) > 0) {
            throw new InvalidDocument('A document discount cannot exceed the amount it discounts.');
        }

        $groups = [];
        foreach ($lines as $i => $line) {
            $groups[implode("\0", array_map(static fn (TaxInput $tax) => $tax->code, $line->taxes))][] = $i;
        }
        $groups = array_values($groups);
        $weights = array_map(static fn (array $members) => Decimal::sum(array_map(static fn (int $i) => $bases[$i], $members)), $groups);
        $groupShares = Decimal::allocate(
            $discount,
            array_map(static fn (Number $weight) => $discount->mul($weight)->div($total, Decimal::WORKING_SCALE), $weights),
            $scale,
        );

        $byLine = [];
        foreach ($groups as $g => $members) {
            if (0 === $weights[$g]->compare(0)) {
                continue;
            }
            $split = Decimal::allocate(
                $groupShares[$g],
                array_map(static fn (int $i) => $groupShares[$g]->mul($bases[$i])->div($weights[$g], Decimal::WORKING_SCALE), $members),
                $scale,
            );
            foreach ($members as $k => $i) {
                $byLine[$i] = $split[$k];
            }
        }

        return array_map(static fn (int $i) => $byLine[$i] ?? Decimal::zero(), array_keys($bases));
    }

    /**
     * Tax-exclusive: each percentage tax on its lines' discounted nets, a levy entering the VAT base first.
     *
     * @param list<Number> $nets
     *
     * @return array{list<Number>, list<list<array{string, Number, Number}>>, list<array{TaxInput, Number, Number, list<int>}>}
     */
    private function add(DocumentInput $document, array $nets, int $scale): array
    {
        $taxes = $this->taxesInOrder($document);
        $computed = array_map(static fn () => [], $nets);
        $groups = [];
        $entering = array_filter($taxes, static fn (TaxInput $tax) => $tax->entersVatBase);
        foreach ([...$entering, ...array_filter($taxes, static fn (TaxInput $tax) => !$tax->entersVatBase)] as $tax) {
            \assert(null !== $tax->rate);
            $rate = $tax->rate->fraction();
            $carriers = $this->carriers($document, $tax->code);
            $bases = [];
            foreach ($carriers as $i) {
                $base = $nets[$i];
                if (!$tax->entersVatBase) {
                    foreach ($entering as $levy) {
                        $base = $base->add($computed[$i][$levy->code][1] ?? Decimal::zero());
                    }
                }
                $bases[] = $base;
            }
            $exact = array_map(static fn (Number $base) => $base->mul($rate, Decimal::WORKING_SCALE), $bases);
            if (RoundingPoint::PerLine === $document->roundingPoint) {
                $amounts = array_map(static fn (Number $share) => Decimal::round($share, $scale), $exact);
                $amount = Decimal::sum($amounts);
            } else {
                $amount = Decimal::round(Decimal::sum($exact), $scale);
                $amounts = Decimal::allocate($amount, $exact, $scale);
            }
            foreach ($carriers as $k => $i) {
                $computed[$i][$tax->code] = [$bases[$k], $amounts[$k]];
            }
            $groups[$tax->code] = [$tax, Decimal::sum($bases), $amount, $carriers];
        }

        return [$nets, $this->inLineOrder($document, $computed), $this->inFirstAppearance($taxes, $groups)];
    }

    /**
     * Tax-inclusive: the net is extracted from the gross and the tax is the residue. Per rate group the net is
     * rounded once on the summed gross and each line's tax share is allocated; per line each line extracts alone.
     *
     * @param list<Number> $grosses
     *
     * @return array{list<Number>, list<list<array{string, Number, Number}>>, list<array{TaxInput, Number, Number, list<int>}>}
     */
    private function extract(DocumentInput $document, array $grosses, int $scale): array
    {
        $taxes = $this->taxesInOrder($document);
        $nets = $grosses;
        $computed = array_map(static fn () => [], $grosses);
        $groups = [];
        foreach ($taxes as $tax) {
            \assert(null !== $tax->rate);
            $divisor = $tax->rate->fraction()->add(1);
            $carriers = $this->carriers($document, $tax->code);
            if (RoundingPoint::PerLine === $document->roundingPoint) {
                foreach ($carriers as $i) {
                    $nets[$i] = Decimal::round($grosses[$i]->div($divisor, Decimal::WORKING_SCALE), $scale);
                }
            } else {
                $gross = Decimal::sum(array_map(static fn (int $i) => $grosses[$i], $carriers));
                $residue = $gross->sub(Decimal::round($gross->div($divisor, Decimal::WORKING_SCALE), $scale));
                $shares = Decimal::allocate(
                    $residue,
                    array_map(static fn (int $i) => $grosses[$i]->mul($tax->rate->fraction())->div($divisor, Decimal::WORKING_SCALE), $carriers),
                    $scale,
                );
                foreach ($carriers as $k => $i) {
                    $nets[$i] = $grosses[$i]->sub($shares[$k]);
                }
            }
            $amount = Decimal::zero();
            $base = Decimal::zero();
            foreach ($carriers as $i) {
                $computed[$i][$tax->code] = [$nets[$i], $grosses[$i]->sub($nets[$i])];
                $amount = $amount->add($grosses[$i]->sub($nets[$i]));
                $base = $base->add($nets[$i]);
            }
            $groups[$tax->code] = [$tax, $base, $amount, $carriers];
        }

        // Every write above replaced an existing key, which keeps the lines' order.
        return [array_values($nets), $this->inLineOrder($document, $computed), $this->inFirstAppearance($taxes, $groups)];
    }

    /** @return list<TaxInput> each percentage tax once, in the order it first appears on the lines */
    private function taxesInOrder(DocumentInput $document): array
    {
        $taxes = [];
        foreach ($document->lines as $line) {
            foreach ($line->taxes as $tax) {
                $taxes[$tax->code] ??= $tax;
            }
        }

        return array_values($taxes);
    }

    /** @return list<int> the lines carrying a tax */
    private function carriers(DocumentInput $document, string $code): array
    {
        $carriers = [];
        foreach ($document->lines as $i => $line) {
            foreach ($line->taxes as $tax) {
                if ($tax->code === $code) {
                    $carriers[] = $i;
                }
            }
        }

        return $carriers;
    }

    /**
     * @param list<array<string, array{Number, Number}>> $computed
     *
     * @return list<list<array{string, Number, Number}>> each line's taxes in the order the line lists them
     */
    private function inLineOrder(DocumentInput $document, array $computed): array
    {
        $out = [];
        foreach ($document->lines as $i => $line) {
            $out[] = array_map(static fn (TaxInput $tax) => [$tax->code, ...$computed[$i][$tax->code]], $line->taxes);
        }

        return $out;
    }

    /**
     * @param list<TaxInput>                                            $taxes
     * @param array<string, array{TaxInput, Number, Number, list<int>}> $groups
     *
     * @return list<array{TaxInput, Number, Number, list<int>}>
     */
    private function inFirstAppearance(array $taxes, array $groups): array
    {
        return array_map(static fn (TaxInput $tax) => $groups[$tax->code], $taxes);
    }

    private function guard(DocumentInput $document): void
    {
        if ($document->scale < 0 || $document->scale > 4) {
            throw new InvalidDocument('A currency has between 0 and 4 decimals.');
        }
        $seen = [];
        foreach ($document->lines as $i => $line) {
            if (Decimal::of($line->quantity)->compare(0) < 0 || Decimal::of($line->unitPrice)->compare(0) < 0) {
                throw new InvalidDocument(\sprintf('Line %d: a quantity or a unit price is never negative; a credit note carries the sign.', $i + 1));
            }
            if (null !== $line->discountRate) {
                $rate = Decimal::of($line->discountRate);
                if ($rate->compare(0) < 0 || $rate->compare(100) > 0) {
                    throw new InvalidDocument(\sprintf('Line %d: a discount rate lies between 0 and 100.', $i + 1));
                }
            }
            foreach ($line->taxes as $tax) {
                if (TaxKind::PercentageLine !== $tax->kind) {
                    throw new InvalidDocument(\sprintf('Line %d: %s is not a percentage tax, and only those belong to a line.', $i + 1, $tax->code));
                }
                $seen = $this->remember($seen, $tax);
            }
            $compound = \count($line->taxes) > 1 || (1 === \count($line->taxes) && $line->taxes[0]->entersVatBase);
            if (TaxBasis::Inclusive === $document->basis && $compound) {
                throw new UnsupportedTaxCombination(\sprintf('Line %d: tax-inclusive prices cannot carry a tax entering the VAT base or several percentage taxes.', $i + 1));
            }
        }
        if (null !== $document->documentDiscount) {
            if (Decimal::of($document->documentDiscount)->compare(0) < 0) {
                throw new InvalidDocument('A document discount is never negative; a credit note carries the sign.');
            }
            $this->fitsScale($document->documentDiscount, $document->scale, 'The document discount');
        }
        foreach ($document->documentTaxes as $tax) {
            if (TaxKind::PercentageLine === $tax->kind) {
                throw new InvalidDocument(\sprintf('%s is a percentage tax, which belongs to lines.', $tax->code));
            }
            $seen = $this->remember($seen, $tax);
            $this->fitsScale((string) ($tax->amount ?? $tax->threshold), $document->scale, $tax->code);
        }
    }

    /**
     * @param array<string, TaxInput> $seen
     *
     * @return array<string, TaxInput>
     */
    private function remember(array $seen, TaxInput $tax): array
    {
        if (isset($seen[$tax->code]) && !$seen[$tax->code]->sameAs($tax)) {
            throw new InvalidDocument(\sprintf('%s names two different taxes on one document.', $tax->code));
        }
        $seen[$tax->code] = $tax;

        return $seen;
    }

    private function fitsScale(string $amount, int $scale, string $what): void
    {
        $value = Decimal::of($amount);
        if (0 !== Decimal::round($value, $scale)->compare($value)) {
            throw new AmountTooPrecise(\sprintf('%s (%s) has more decimals than the currency.', $what, $amount));
        }
    }
}
