<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\InvalidStockMovement;
use App\Module\Inventory\Domain\StockLevel;
use App\Module\Inventory\Domain\StockLevelSearch;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationRepository;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Module\Inventory\Domain\StockMovementSearch;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductRepository;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use App\Shared\Application\Transactions;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The stock a company keeps (docs/SPEC.md § 5 G10): goods whose `article.stock_tracking` resolves on for them, their
 * category or the company. Stock is received and counted by a person at a location; a count records the difference from
 * the stock it found, in the transaction reading it. The movements are the record: no audit row repeats them, so each is
 * staged as a live change here instead (docs/SPEC.md § 7, 2026-09-17).
 */
final readonly class KeepStock
{
    public function __construct(
        private StockMovementRepository $movements,
        private StockLocationRepository $locations,
        private ProductRepository $products,
        private ReadSetting $settings,
        private Transactions $transactions,
        private ClockInterface $clock,
        private LiveChanges $liveChanges,
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
        return $this->transactions->run(function () use ($company, $productId, $locationId, $quantity, $actorUserId): StockMovement {
            [$product, $location] = $this->trackedAt($company, $productId, $locationId);
            $movement = StockMovement::receipt($product, $location, $quantity, $actorUserId, $this->clock->now());
            $this->movements->save($movement);
            $this->liveChanges->stage(new LiveChange('stock', $productId, 'stock.received', $actorUserId, $company->getId()));

            return $movement;
        });
    }

    /** @throws InvalidStockMovement */
    public function count(Company $company, Uuid $productId, Uuid $locationId, string $counted, ?Uuid $actorUserId): StockMovement
    {
        return $this->transactions->run(function () use ($company, $productId, $locationId, $counted, $actorUserId): StockMovement {
            [$product, $location] = $this->trackedAt($company, $productId, $locationId);
            $this->movements->lockStockOf($product->getId(), $location->getId());
            $movement = StockMovement::count($product, $location, $counted, $this->movements->onHand($productId, $locationId), $actorUserId, $this->clock->now());
            $this->movements->save($movement);
            $this->liveChanges->stage(new LiveChange('stock', $productId, 'stock.counted', $actorUserId, $company->getId()));

            return $movement;
        });
    }

    /** @return list<StockLevel> */
    public function levels(Company $company): array
    {
        return $this->movements->levels($company->getId());
    }

    /**
     * One page of the same, searched, narrowed and sorted by the database.
     *
     * @return Page<StockLevel>
     */
    public function searchLevels(Company $company, StockLevelSearch $search, PageRequest $page): Page
    {
        return $this->movements->searchLevels($company->getId(), $search, $page);
    }

    /**
     * One page of a company's movements, narrowed and ordered as the list asked (docs/SPEC.md row 55 (b)).
     *
     * This replaced a reader that answered the latest two hundred for the browser to cut up: a company past that cap
     * simply stopped seeing its older history, and no amount of paging in the browser can show what was never sent.
     *
     * @return Page<StockMovement>
     */
    public function searchMovements(Company $company, StockMovementSearch $search, PageRequest $page): Page
    {
        return $this->movements->searchMovements($company->getId(), $search, $page);
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
