<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Module\Products\Domain\Exception\InvalidProduct;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Model\ProductCostChange;
use App\Module\Products\Domain\Repository\ProductCostChangeRepository;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Module\Products\Domain\ValueObject\CostChangeSource;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Changes what a product costs the company on its own, as a receipt does, keeping the cost it had and what moved it.
 * The stock context calls it inside the transaction of the receipt, so the cost and its history commit with the goods.
 * The same figure is no change, and writes nothing. Audited by the name of the field, like any revision of a product.
 * A receipt moving the cost under a price that stays moves the margin: what follows is the company's setting, through
 * PriceOnCostMove, said to whoever received in that receipt's answer.
 */
final readonly class ChangeProductCost implements DeclaresAuditActions
{
    public function __construct(
        private ProductRepository $products,
        private ProductCostChangeRepository $history,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
        private PriceOnCostMove $priceMove,
    ) {
    }

    /**
     * @param string|null $cost the new cost, up to four decimals; null takes the cost away
     *
     * @return bool whether the cost changed
     *
     * @throws InvalidProduct
     * @throws ProductNotFound
     */
    public function handle(Company $company, Product $product, ?string $cost, CostChangeSource $source, ?Uuid $sourceId, ?Uuid $actorUserId): bool
    {
        return $this->transactions->run(function () use ($company, $product, $cost, $source, $sourceId, $actorUserId): bool {
            // Read again under the row lock: a person's save of this product since it was read is waited for and taken
            // in, where the unlocked object would be refused at the flush for a version saved in between.
            $product = $this->products->lockedOfIdInCompany($product->getId(), $company->getId()) ?? throw new ProductNotFound();
            $before = $product->getDetails()->costPrice;
            $now = $this->clock->now();
            if (!$product->reviseCost($cost, $now)) {
                return false;
            }
            $after = $product->getDetails()->costPrice;
            if ((null !== $before && !is_numeric($before)) || (null !== $after && !is_numeric($after))) {
                throw new \LogicException('A product cost is a number or nothing.');
            }
            $fields = CostChangeSource::Receipt === $source ? $this->priceMove->after($company, $product, $before, $now) : ['costPrice'];
            $this->products->save($product);
            $this->history->save(new ProductCostChange($product, $before, $after, $source, $sourceId, $actorUserId, $now));
            $this->audit->record(new AuditEntry(ManageProducts::ENTITY_TYPE, $product->getId(), ManageProducts::REVISED, $actorUserId, ['fields' => $fields], $company->getId()));

            return true;
        });
    }

    public function auditActions(): array
    {
        return [
            ManageProducts::REVISED => new Recorded(ManageProducts::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
