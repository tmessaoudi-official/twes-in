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
use App\Module\Products\Domain\ValueObject\PriceReviewDecision;
use App\Shared\Application\RecordVersions;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Settles the margin a receipt's cost moved under a kept price: adjusted to the price that keeps the margin the product
 * had, or the price kept as it is; or puts back the price a cost moved away when the price followed it, while the price
 * is the one the follow set. Each is a revision of the product, audited by the field it changed, so every screen showing
 * the product hears it; one settled since it was read is refused by its version.
 */
final readonly class ReviewProductPrice
{
    public const string NOTHING_TO_REVIEW = 'The margin of this product has not moved since its price was set.';

    public const string NOTHING_TO_RESTORE = 'No price of this product followed its cost since it was last set: there is none to put back.';

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
     * @throws ProductNotFound
     * @throws ProductMergedAway
     * @throws NothingToReview
     */
    public function handle(Company $company, Uuid $id, PriceReviewDecision $decision, int $version, ?Uuid $actorUserId): Product
    {
        return $this->transactions->run(function () use ($company, $id, $decision, $version, $actorUserId): Product {
            $product = $this->products->lockedOfIdInCompany($id, $company->getId()) ?? throw new ProductNotFound();
            $this->versions->expect($product, $version);
            if ($product->isMergedAway()) {
                throw new ProductMergedAway(\sprintf('The product %s was merged away: its price is no longer reviewed.', $product->getReference()));
            }
            $restore = PriceReviewDecision::Restore === $decision;
            if ($restore ? !$product->mayRestoreFollowedPrice() : null === $product->priceReview()) {
                throw new NothingToReview($restore ? self::NOTHING_TO_RESTORE : self::NOTHING_TO_REVIEW);
            }
            $now = $this->clock->now();
            match ($decision) {
                PriceReviewDecision::Adjust => $product->adjustPriceToReview($this->scales->of($company->getCurrency()), $now),
                PriceReviewDecision::Keep => $product->closePriceReview($now),
                PriceReviewDecision::Restore => $product->restoreFollowedPrice($now),
            };
            $this->products->save($product);
            $this->audit->record(new AuditEntry(ManageProducts::ENTITY_TYPE, $product->getId(), ManageProducts::REVISED, $actorUserId, ['fields' => [PriceReviewDecision::Keep === $decision ? 'priceReview' : 'unitPriceNet']], $company->getId()));

            return $product;
        });
    }
}
