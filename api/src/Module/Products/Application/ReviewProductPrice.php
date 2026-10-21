<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Fiscal\Application\CurrencyScales;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Shared\Application\RecordVersions;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Settles the margin a receipt's cost moved under a kept price: adjusted to the price that keeps the margin the product
 * had, or the price kept as it is. Either is a revision of the product, audited by the field it changed, so every screen
 * showing the product hears it; one settled since it was read is refused by its version.
 */
final readonly class ReviewProductPrice
{
    public const string NOTHING_TO_REVIEW = 'The margin of this product has not moved since its price was set.';

    public function __construct(
        private ProductRepository $products,
        private RecordVersions $versions,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
        private CurrencyScales $scales,
    ) {
    }

    /**
     * @param bool $adjust true sets the price that keeps the margin, false keeps the price
     *
     * @throws ProductNotFound
     * @throws ProductMergedAway
     * @throws NothingToReview
     */
    public function handle(Company $company, Uuid $id, bool $adjust, int $version, ?Uuid $actorUserId): Product
    {
        return $this->transactions->run(function () use ($company, $id, $adjust, $version, $actorUserId): Product {
            $product = $this->products->lockedOfIdInCompany($id, $company->getId()) ?? throw new ProductNotFound();
            $this->versions->expect($product, $version);
            if ($product->isMergedAway()) {
                throw new ProductMergedAway(\sprintf('The product %s was merged away: its price is no longer reviewed.', $product->getReference()));
            }
            if (null === $product->priceReview()) {
                throw new NothingToReview(self::NOTHING_TO_REVIEW);
            }
            $now = $this->clock->now();
            if ($adjust) {
                $product->adjustPriceToReview($this->scales->of($company->getCurrency()), $now);
            } else {
                $product->closePriceReview($now);
            }
            $this->products->save($product);
            $this->audit->record(new AuditEntry(ManageProducts::ENTITY_TYPE, $product->getId(), ManageProducts::REVISED, $actorUserId, ['fields' => [$adjust ? 'unitPriceNet' : 'priceReview']], $company->getId()));

            return $product;
        });
    }
}
