<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Domain\Calculation\DocumentCalculator;
use App\Fiscal\Domain\Calculation\DocumentInput;
use App\Fiscal\Domain\Calculation\InvalidDocument;
use App\Fiscal\Domain\Calculation\LineInput;
use App\Fiscal\Domain\Calculation\Rate;
use App\Fiscal\Domain\Calculation\TaxBasis;
use App\Fiscal\Domain\Calculation\TaxInput;
use App\Fiscal\Domain\Calculation\UnsupportedTaxCombination;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\TaxKind;
use App\Module\Products\Domain\Product;
use Symfony\Component\Uid\Uuid;

/**
 * What a customer pays for a product, taxes included (docs/SPEC.md § 7, 2026-09-23 slice 6, the price check): the
 * quantity on one line of a document at its net price, with the line taxes the product starts a line with, counted by
 * the calculator every document uses, at the company's currency scale and VAT rounding. So a pack is priced as twelve
 * units on one line, exactly as the invoice for it would be, and a tax entering the VAT base (FODEC) is counted there.
 * No document tax (a stamp) and no customer's regime: this is the shelf price, for anyone.
 */
final readonly class CustomerPrice
{
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
    public function of(Product $product, int $quantity): string
    {
        $company = $product->getCompany();
        $taxes = [];
        foreach ($product->getDefaultTaxComponentIds() as $id) {
            // ManageProducts keeps only the company's line taxes here, so anything else is a broken invariant, and a
            // price counted without one of its taxes would be a wrong price shown to a customer.
            $tax = $this->taxes->ofIdInCompany(Uuid::fromString($id), $company->getId());
            $rate = $tax?->getRate();
            if (null === $tax || TaxKind::PercentageLine !== $tax->getKind() || null === $rate) {
                throw new \LogicException(\sprintf('The product %s starts its lines with %s, which is not a line tax of its company.', $product->getReference(), $id));
            }
            $taxes[] = TaxInput::percentage($tax->getCode(), Rate::fromPercentage($rate), $tax->entersVatBase());
        }

        return new DocumentCalculator()->calculate(new DocumentInput(
            $this->scales->of($company->getCurrency()),
            false,
            TaxBasis::Exclusive,
            $this->presets->get($company->getFiscalPreset())->vatRoundingPoint,
            [new LineInput((string) $quantity, $product->getDetails()->unitPriceNet, null, $taxes)],
        ))->total;
    }
}
