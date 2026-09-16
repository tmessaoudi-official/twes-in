<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\InvalidStockMovement;
use App\Module\Inventory\Domain\StockLevel;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationRepository;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductRepository;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The stock a company keeps (docs/SPEC.md § 5 G10): goods whose `article.stock_tracking` resolves on for them, their
 * category or the company. Stock is received and counted by a person at a location; a count records the difference from
 * the stock it found, in the transaction reading it. The movements are the record: no audit row repeats them.
 */
final readonly class KeepStock
{
    /** How many of a product's movements are listed at once, newest first. */
    public const int MOVEMENTS_LISTED = 200;

    public function __construct(
        private StockMovementRepository $movements,
        private StockLocationRepository $locations,
        private ProductRepository $products,
        private ReadSetting $settings,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function tracked(Product $product): bool
    {
        if (ProductKind::Goods !== $product->getDetails()->kind) {
            return false;
        }
        $context = new SettingContext($product->getCompany(), productCategoryId: $product->getCategory()?->getId(), productId: $product->getId());

        return true === $this->settings->value($context, 'article.stock_tracking');
    }

    /** @throws InvalidStockMovement */
    public function receive(Company $company, Uuid $productId, Uuid $locationId, string $quantity, ?Uuid $actorUserId): StockMovement
    {
        [$product, $location] = $this->trackedAt($company, $productId, $locationId);
        $movement = StockMovement::receipt($product, $location, $quantity, $actorUserId, $this->clock->now());
        $this->movements->save($movement);

        return $movement;
    }

    /** @throws InvalidStockMovement */
    public function count(Company $company, Uuid $productId, Uuid $locationId, string $counted, ?Uuid $actorUserId): StockMovement
    {
        return $this->transactions->run(function () use ($company, $productId, $locationId, $counted, $actorUserId): StockMovement {
            [$product, $location] = $this->trackedAt($company, $productId, $locationId);
            $this->movements->lockStockOf($product->getId(), $location->getId());
            $movement = StockMovement::count($product, $location, $counted, $this->movements->onHand($productId, $locationId), $actorUserId, $this->clock->now());
            $this->movements->save($movement);

            return $movement;
        });
    }

    /** @return list<StockLevel> */
    public function levels(Company $company): array
    {
        return $this->movements->levels($company->getId());
    }

    /** @return list<StockMovement> the latest of one product's, or of all the company's, newest first; none for another company's product */
    public function movementsOf(Company $company, ?Uuid $productId): array
    {
        return null === $productId
            ? $this->movements->ofCompany($company->getId(), self::MOVEMENTS_LISTED)
            : $this->movements->ofProduct($productId, $company->getId(), self::MOVEMENTS_LISTED);
    }

    /** @return array{Product, StockLocation} */
    private function trackedAt(Company $company, Uuid $productId, Uuid $locationId): array
    {
        $product = $this->products->ofIdInCompany($productId, $company->getId())
            ?? throw new InvalidStockMovement('productId', 'No product of this company has this id.');
        if (!$this->tracked($product)) {
            throw new InvalidStockMovement('productId', \sprintf('No stock is kept of %s: only goods whose stock tracking is on are kept.', $product->getReference()));
        }
        $location = $this->locations->ofIdInCompany($locationId, $company->getId())
            ?? throw new InvalidStockMovement('locationId', 'No stock location of this company has this id.');

        return [$product, $location];
    }
}
