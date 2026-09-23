<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\InvalidStockMovement;
use App\Module\Inventory\Domain\NamedLot;
use App\Module\Inventory\Domain\StockLevel;
use App\Module\Inventory\Domain\StockLevelSearch;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationRepository;
use App\Module\Inventory\Domain\StockLot;
use App\Module\Inventory\Domain\StockLotRepository;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Module\Inventory\Domain\StockMovementSearch;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductRepository;
use App\Module\Products\Domain\ProductTracking;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use App\Shared\Application\Transactions;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;
use BcMath\Number;
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
        private StockLotRepository $lots,
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

    /**
     * Goods arriving at a location. A product tracked by lot or serial number names the lot they came in: its code
     * opens the lot the first time it is seen, and its date fills a lot that had none (docs/SPEC.md § 7, 2026-09-23).
     *
     * @throws InvalidStockMovement
     */
    public function receive(Company $company, Uuid $productId, Uuid $locationId, string $quantity, ?Uuid $actorUserId, ?NamedLot $named = null): StockMovement
    {
        return $this->transactions->run(function () use ($company, $productId, $locationId, $quantity, $actorUserId, $named): StockMovement {
            [$product, $location] = $this->trackedAt($company, $productId, $locationId);
            $lot = $this->lotFor($product, $named, true);
            $movement = StockMovement::receipt($product, $location, $quantity, $actorUserId, $this->clock->now(), $lot);
            $this->inStockOnce($movement);
            $this->save($movement);
            $this->liveChanges->stage(new LiveChange('stock', $productId, 'stock.received', $actorUserId, $company->getId()));

            return $movement;
        });
    }

    /**
     * What a person found at a location; of one lot, for a tracked product, which a count may be the first to see.
     *
     * @throws InvalidStockMovement
     */
    public function count(Company $company, Uuid $productId, Uuid $locationId, string $counted, ?Uuid $actorUserId, ?NamedLot $named = null): StockMovement
    {
        return $this->transactions->run(function () use ($company, $productId, $locationId, $counted, $actorUserId, $named): StockMovement {
            [$product, $location] = $this->trackedAt($company, $productId, $locationId);
            $lot = $this->lotFor($product, $named, true);
            $this->movements->lockStockOf($product->getId(), $location->getId());
            $movement = StockMovement::count($product, $location, $counted, $this->movements->onHand($productId, $locationId, $lot?->getId()), $actorUserId, $this->clock->now(), $lot);
            $this->inStockOnce($movement);
            $this->save($movement);
            $this->liveChanges->stage(new LiveChange('stock', $productId, 'stock.counted', $actorUserId, $company->getId()));

            return $movement;
        });
    }

    /**
     * Goods taken out of one location and into another, as one operation (§ 7 2026-09-19 23:25). The source stock is
     * locked before it is read, as a count does, so two moves of the same goods cannot both find enough there and
     * between them take out more than exists.
     *
     * @return array{StockMovement, StockMovement} what left, then what arrived
     *
     * @throws InvalidStockMovement
     */
    public function move(Company $company, Uuid $productId, Uuid $fromLocationId, Uuid $toLocationId, string $quantity, ?Uuid $actorUserId, ?NamedLot $named = null): array
    {
        return $this->transactions->run(function () use ($company, $productId, $fromLocationId, $toLocationId, $quantity, $actorUserId, $named): array {
            [$product, $from] = $this->trackedAt($company, $productId, $fromLocationId);
            $to = $this->locations->ofIdInCompany($toLocationId, $company->getId())
                ?? throw new InvalidStockMovement('toLocationId', 'No stock location of this company has this id.');
            // A move carries goods that are there, so it names a lot that exists; it never opens one.
            $lot = $this->lotFor($product, $named, false);
            $this->movements->lockStockOf($product->getId(), $from->getId());
            [$out, $in] = StockMovement::move($product, $from, $to, $quantity, $actorUserId, $this->clock->now(), $lot);
            // What arrives is the amount asked for, positive; what leaves is its negative. Read the source AFTER the
            // lock, so what is compared is what no other transaction can be taking at the same time.
            $onHand = $this->movements->onHand($productId, $fromLocationId, $lot?->getId());
            if (1 === new Number($in->getQuantity())->compare(new Number($onHand))) {
                throw new InvalidStockMovement('quantity', \sprintf('Only %s is at that location.', $onHand));
            }
            $this->movements->save($out, $in);
            // One move is one change: the stock of this product moved, once.
            $this->liveChanges->stage(new LiveChange('stock', $productId, 'stock.moved', $actorUserId, $company->getId()));

            return [$out, $in];
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

    /**
     * The lot a movement names, found by its code under a lock on that code, and opened when it may be: a receipt or a
     * count meets new goods, a move only carries what is there. Named for an untracked product, it is refused here
     * rather than dropped, so a person who typed a lot learns the product keeps none.
     *
     * @throws InvalidStockMovement
     */
    private function lotFor(Product $product, ?NamedLot $named, bool $mayOpen): ?StockLot
    {
        if (null === $named) {
            return null;
        }
        if (ProductTracking::None === $product->getTracking()) {
            throw new InvalidStockMovement('lot', \sprintf('The product %s is not tracked by lot or serial number: its stock names no lot.', $product->getReference()));
        }
        $code = StockLot::code($named->code);
        $this->lots->lock($product->getId(), $code);
        $lot = $this->lots->ofCode($product->getId(), $code);
        if (null !== $lot) {
            $lot->dated($named->expiresOn);

            return $lot;
        }
        if (!$mayOpen) {
            throw new InvalidStockMovement('lotCode', \sprintf('%s has no lot %s: goods enter a lot by a receipt or a count.', $product->getReference(), $code));
        }

        return StockLot::open($product, $code, $named->expiresOn, $this->clock->now());
    }

    /**
     * A serial number is one piece, so it is in stock once across the company whatever the locations say: checked
     * under the lock on its code, which every movement naming it takes first.
     *
     * @throws InvalidStockMovement
     */
    private function inStockOnce(StockMovement $movement): void
    {
        $lot = $movement->getLot();
        if (null === $lot || ProductTracking::Serial !== $movement->getProduct()->getTracking()) {
            return;
        }
        if (1 === new Number($this->movements->onHandOfLot($lot->getId()))->add($movement->getQuantity())->compare(1)) {
            throw new InvalidStockMovement('lot', \sprintf('The serial number %s of %s is already in stock.', $lot->getCode(), $movement->getProduct()->getReference()));
        }
    }

    /** The movement, with its lot first when it names one: a lot is written with the first goods that enter it. */
    private function save(StockMovement $movement): void
    {
        $lot = $movement->getLot();
        if (null !== $lot) {
            $this->lots->save($lot);
        }
        $this->movements->save($movement);
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
