<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Fiscal\Application\CurrencyScales;
use App\Module\Products\Domain\Model\Product;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\Effect;
use App\Shared\Application\Effects;
use App\Tenancy\Domain\Model\Company;

/**
 * What the company's setting (ProductPriceSettings) does to a price its cost moved under, when nobody was looking at that
 * price: a receipt, or a file read in that gives the cost and leaves the price. The price kept and the move offered for
 * review by default, the price moved to keep the margin, or nothing; never a margin moved in silence: what was done is
 * said to whoever sent the request (`Effects`), in its answer. A cost typed in the product is seen beside its price and
 * asks nothing.
 */
final readonly class PriceOnCostMove
{
    /** The price moved with its cost, as the company's setting asks; said with the price it had. */
    public const string PRICE_FOLLOWED = 'product.price_followed';

    /** The cost moved under a price kept for review: the margin moved. */
    public const string MARGIN_MOVED = 'product.margin_moved';

    public function __construct(
        private ReadSetting $settings,
        private CurrencyScales $scales,
        private Effects $effects,
    ) {
    }

    /**
     * Applies the setting to a product whose cost was `$before`; the caller saves the product.
     *
     * @return list<string> the fields the move changed
     */
    public function after(Company $company, Product $product, ?string $before, \DateTimeImmutable $now): array
    {
        $context = new SettingContext($company, productCategoryId: $product->getCategory()?->getId(), productId: $product->getId());
        $mode = ProductPriceSettings::modeOf($this->settings->value($context, ProductPriceSettings::ON_COST_CHANGE));
        if (PriceOnCostChange::Keep === $mode) {
            return ['costPrice'];
        }
        $product->openPriceReview($before, $now);
        if (null === $product->priceReview()) {
            return ['costPrice'];
        }
        if (PriceOnCostChange::Follow !== $mode) {
            $this->effects->said(new Effect(self::MARGIN_MOVED, $product->getId()));

            return ['costPrice'];
        }
        $priceBefore = $product->getDetails()->unitPriceNet;
        $product->adjustPriceToReview($this->scales->of($company->getCurrency()), $now);
        $this->effects->said(new Effect(self::PRICE_FOLLOWED, $product->getId(), ['previousPrice' => $priceBefore]));

        return ['costPrice', 'unitPriceNet'];
    }
}
