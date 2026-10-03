<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Module\Products\Domain\CostChangeSource;
use App\Module\Products\Domain\InvalidProduct;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductCostChange;
use App\Module\Products\Domain\ProductCostChangeRepository;
use App\Module\Products\Domain\ProductRepository;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Changes what a product costs the company on its own, as a receipt does, keeping the cost it had and what moved it.
 * The stock context calls it inside the transaction of the receipt, so the cost and its history commit with the goods.
 * The same figure is no change, and writes nothing. Audited by the name of the field, like any revision of a product.
 */
final readonly class ChangeProductCost
{
    public function __construct(
        private ProductRepository $products,
        private ProductCostChangeRepository $history,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    /**
     * @param string|null $cost the new cost, up to four decimals; null takes the cost away
     *
     * @return bool whether the cost changed
     *
     * @throws InvalidProduct
     */
    public function handle(Company $company, Product $product, ?string $cost, CostChangeSource $source, ?Uuid $sourceId, ?Uuid $actorUserId): bool
    {
        return $this->transactions->run(function () use ($company, $product, $cost, $source, $sourceId, $actorUserId): bool {
            $before = $product->getDetails()->costPrice;
            $now = $this->clock->now();
            if (!$product->reviseCost($cost, $now)) {
                return false;
            }
            $after = $product->getDetails()->costPrice;
            if ((null !== $before && !is_numeric($before)) || (null !== $after && !is_numeric($after))) {
                throw new \LogicException('A product cost is a number or nothing.');
            }
            $this->products->save($product);
            $this->history->save(new ProductCostChange($product, $before, $after, $source, $sourceId, $actorUserId, $now));
            $this->audit->record(new AuditEntry(ManageProducts::ENTITY_TYPE, $product->getId(), ManageProducts::REVISED, $actorUserId, ['fields' => ['costPrice']], $company->getId()));

            return true;
        });
    }
}
