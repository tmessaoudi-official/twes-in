<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\CostBasis;
use App\Module\Inventory\Domain\CostOnReceive;
use App\Module\Inventory\Domain\InvalidStockMovement;
use App\Module\Inventory\Domain\LateCost;
use App\Module\Inventory\Domain\NamedLot;
use App\Module\Inventory\Domain\ReceiptDocument;
use App\Module\Inventory\Domain\RunningValue;
use App\Module\Inventory\Domain\StockLevel;
use App\Module\Inventory\Domain\StockLevelSearch;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationRepository;
use App\Module\Inventory\Domain\StockLossReason;
use App\Module\Inventory\Domain\StockLot;
use App\Module\Inventory\Domain\StockLotRepository;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementCostKnown;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Module\Inventory\Domain\StockMovementSearch;
use App\Module\Inventory\Domain\StockValue;
use App\Module\Products\Domain\InvalidProduct;
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
use App\Shared\Domain\Tree;
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
        private ?RaiseStockAlerts $alerts = null,
        private ?ReceiptCosts $receiptCosts = null,
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
     * opens the lot the first time it is seen, and its date fills a lot that had none. A receipt that comes with a cost
     * may move the product's own cost to the weighted average or to that cost, as the company's setting says; `$apply` is
     * the person's choice, honoured only where the setting offers one.
     *
     * @throws InvalidStockMovement
     */
    public function receive(Company $company, Uuid $productId, Uuid $locationId, string $quantity, ?Uuid $actorUserId, ?NamedLot $named = null, ?string $unitCost = null, ?CostBasis $apply = null, ?ReceiptDocument $document = null, bool $costToComplete = false): StockMovement
    {
        return $this->transactions->run(fn (): StockMovement => $this->receiptsAt($company, $productId, [['locationId' => $locationId, 'quantity' => $quantity]], $actorUserId, $named, $unitCost, $apply, $document, $costToComplete)[0]);
    }

    /**
     * One delivery shared out over several places of a company, each place with its own quantity: one receipt per place,
     * stored whole or not at all, so the total on the shelves is always the sum of the movements and never half of
     * a delivery. Every place is checked before any is written, and a place may appear once.
     *
     * @param list<array{locationId: Uuid, quantity: string}> $parts in the order the person gave them
     *
     * @return list<StockMovement> the receipts, in the same order
     *
     * @throws InvalidStockMovement
     */
    public function receiveSplit(Company $company, Uuid $productId, array $parts, ?Uuid $actorUserId, ?NamedLot $named = null, ?string $unitCost = null, ?CostBasis $apply = null, ?ReceiptDocument $document = null, bool $costToComplete = false): array
    {
        $this->placesOnce($company, $productId, $parts, 'A receipt needs at least one place.', 'A place may appear once in a receipt: add its quantities together.');

        return $this->transactions->run(fn (): array => $this->receiptsAt($company, $productId, $parts, $actorUserId, $named, $unitCost, $apply, $document, $costToComplete));
    }

    /**
     * Every place of a split movement checked before any is written: at least one, each the company's and tracked for
     * the product, none twice.
     *
     * @param list<array{locationId: Uuid, quantity: string}> $parts
     *
     * @phpstan-assert non-empty-list<array{locationId: Uuid, quantity: string}> $parts
     *
     * @throws InvalidStockMovement
     */
    private function placesOnce(Company $company, Uuid $productId, array $parts, string $none, string $twice): void
    {
        if ([] === $parts) {
            throw new InvalidStockMovement('parts', $none);
        }
        $seen = [];
        foreach ($parts as $part) {
            $key = $part['locationId']->toRfc4122();
            if (isset($seen[$key])) {
                throw new InvalidStockMovement('locationId', $twice);
            }
            $seen[$key] = true;
            $this->trackedAt($company, $productId, $part['locationId']);
        }
    }

    /**
     * The receipts of one delivery, then the product's cost moved once, from the last of them: a delivery put away on
     * several shelves is one arrival, and a cost moved per shelf would leave a history row for every figure on the way
     * (audit 2026-10-06, E-12). The live change is one per product already, staged once per transaction.
     *
     * @param non-empty-list<array{locationId: Uuid, quantity: string}> $parts
     *
     * @return non-empty-list<StockMovement>
     *
     * @throws InvalidStockMovement
     */
    private function receiptsAt(Company $company, Uuid $productId, array $parts, ?Uuid $actorUserId, ?NamedLot $named, ?string $unitCost, ?CostBasis $apply, ?ReceiptDocument $document, bool $costToComplete): array
    {
        $written = [];
        $typed = null;
        foreach ($parts as $part) {
            [$written[], $typed] = $this->receiptAt($company, $productId, $part['locationId'], $part['quantity'], $actorUserId, $named, $unitCost, $document, $costToComplete);
        }
        $last = $written[\count($written) - 1];
        $this->moveCost($company, $last->getProduct(), $last, $typed, $apply, $actorUserId);

        return $written;
    }

    /**
     * @return array{StockMovement, numeric-string|null} the receipt, and the cost it came with, none when nobody typed one
     *
     * @throws InvalidStockMovement
     */
    private function receiptAt(Company $company, Uuid $productId, Uuid $locationId, string $quantity, ?Uuid $actorUserId, ?NamedLot $named, ?string $unitCost, ?ReceiptDocument $document, bool $costToComplete): array
    {
        [$product, $location] = $this->trackedAt($company, $productId, $locationId);
        $lot = $this->lotFor($product, $named, true);
        $movement = StockMovement::receipt($product, $location, $quantity, $actorUserId, $this->clock->now(), $lot, $unitCost, $document, $costToComplete);
        // Read before saving: a receipt typed with no cost is valued at the average when it is saved, and that
        // figure is not a price anybody typed.
        $typed = $movement->getUnitCost();
        $this->inStockOnce($movement);
        $this->save($movement);
        $this->liveChanges->stage(new LiveChange('stock', $productId, 'stock.received', $actorUserId, $company->getId()));

        return [$movement, $typed];
    }

    /**
     * A cost reader enters the cost of a receipt left « à compléter » by someone who could not read costs (docs/SPEC.md
     * § 7, audit 2026-10-06 C challenge 9): the receipt is worth that from then on, and the product's cost moves as the
     * company's setting says, as it would have on the receipt.
     *
     * @throws StockMovementNotFound
     * @throws StockMovementCostKnown
     * @throws InvalidStockMovement
     */
    public function enterCost(Company $company, Uuid $movementId, string $unitCost, ?CostBasis $apply, ?Uuid $actorUserId): StockMovement
    {
        return $this->transactions->run(function () use ($company, $movementId, $unitCost, $apply, $actorUserId): StockMovement {
            $receipt = $this->movements->ofIdInCompany($movementId, $company->getId()) ?? throw new StockMovementNotFound();
            $before = RunningValue::of($this->movements->valuedTotalsBefore($receipt));
            $provisional = $receipt->value();
            $receipt->costEntered($unitCost, $before);
            $this->movements->saveValued($receipt);
            $this->costOfSalesShare($receipt, $provisional, $before, $actorUserId);
            $this->moveCost($company, $receipt->getProduct(), $receipt, $receipt->getUnitCost(), $apply, $actorUserId);
            $this->liveChanges->stage(new LiveChange('stock', $receipt->getProduct()->getId(), 'stock.cost_entered', $actorUserId, $company->getId()));

            return $receipt;
        });
    }

    /**
     * What the entered cost changed, split (docs/SPEC.md § 7, the B-F4 ruling): the share that went with the goods gone
     * since the receipt is booked today against the cost of sales, so the stock left is worth what it cost and the
     * average the product cost may move to is that cost, not the whole difference crowded onto what remains.
     *
     * @param numeric-string $provisional what the receipt was worth before its cost was entered
     *
     * @throws InvalidStockMovement
     */
    private function costOfSalesShare(StockMovement $receipt, string $provisional, RunningValue $before, ?Uuid $actorUserId): void
    {
        $since = array_values(array_map(
            static fn (StockMovement $later): string => $later->getQuantity(),
            array_filter($this->movements->valuedAfter($receipt), static fn (StockMovement $later): bool => StockMovement::SOURCE_MOVE !== $later->getSourceType()),
        ));
        $difference = new Number($receipt->value())->sub($provisional)->value;
        $sold = LateCost::soldShare($difference, new Number($before->quantity)->add($receipt->getQuantity())->value, $since);
        if (null !== $sold) {
            $this->movements->saveValued(StockMovement::costOfSalesCorrection($receipt, $sold, $actorUserId, $this->clock->now()));
        }
    }

    /**
     * @param numeric-string|null $typed the cost the receipt came with, none when nobody typed one
     *
     * @throws InvalidStockMovement
     */
    private function moveCost(Company $company, Product $product, StockMovement $receipt, ?string $typed, ?CostBasis $asked, ?Uuid $actorUserId): void
    {
        if (null === $this->receiptCosts || null === $typed) {
            return;
        }
        $context = new SettingContext($company, productCategoryId: $product->getCategory()?->getId(), productId: $product->getId());
        $chosen = $this->settings->value($context, StockCostSettings::COST_ON_RECEIVE);
        $mode = (\is_string($chosen) ? CostOnReceive::tryFrom($chosen) : null) ?? CostOnReceive::Suggest;
        $basis = match ($mode) {
            CostOnReceive::Suggest => $asked,
            CostOnReceive::Average => CostBasis::Average,
            CostOnReceive::Last => CostBasis::Last,
            CostOnReceive::Manual => null,
        };
        if (null === $basis) {
            return;
        }
        $cost = CostBasis::Last === $basis ? $typed : $this->movements->averageCostOf($product);
        if (null !== $cost) {
            try {
                $this->receiptCosts->received($company, $product, $cost, $receipt->getId(), $actorUserId);
            } catch (InvalidProduct $refused) {
                throw new InvalidStockMovement('unitCost', $refused->getMessage(), $refused);
            }
        }
    }

    /**
     * What a person found at a location; of one lot, for a tracked product, which a count may be the first to see.
     *
     * @throws InvalidStockMovement
     */
    public function count(Company $company, Uuid $productId, Uuid $locationId, string $counted, ?Uuid $actorUserId, ?NamedLot $named = null): StockMovement
    {
        return $this->transactions->run(fn (): StockMovement => $this->countsAt($company, $productId, [['locationId' => $locationId, 'quantity' => $counted]], $actorUserId, $named)[0]);
    }

    /**
     * What was found at several places of a company, each place with its own quantity, as an opening count is taken:
     * one count per place, stored whole or not at all, so a count refused at one place moves no other. Each part is
     * what was found there, not a share of a total. Every place is checked before any is written, and a place may
     * appear once.
     *
     * @param list<array{locationId: Uuid, quantity: string}> $parts in the order the person gave them
     *
     * @return list<StockMovement> the counts, in the same order
     *
     * @throws InvalidStockMovement
     */
    public function countSplit(Company $company, Uuid $productId, array $parts, ?Uuid $actorUserId, ?NamedLot $named = null): array
    {
        $this->placesOnce($company, $productId, $parts, 'A count needs at least one place.', 'A place may appear once in a count: count it as one.');

        return $this->transactions->run(fn (): array => $this->countsAt($company, $productId, $parts, $actorUserId, $named));
    }

    /**
     * The counts of every place, then the alerts raised once over all of them: five missing on the floor and found on
     * the rack is no fall, which judging the floor before the rack is written would announce (audit 2026-10-06, N-e).
     *
     * @param non-empty-list<array{locationId: Uuid, quantity: string}> $parts
     *
     * @return non-empty-list<StockMovement>
     *
     * @throws InvalidStockMovement
     */
    private function countsAt(Company $company, Uuid $productId, array $parts, ?Uuid $actorUserId, ?NamedLot $named): array
    {
        $written = [];
        foreach ($parts as $part) {
            $written[] = $this->countAt($company, $productId, $part['locationId'], $part['quantity'], $actorUserId, $named);
        }
        $this->alerts?->raise($written);

        return $written;
    }

    /** @throws InvalidStockMovement */
    private function countAt(Company $company, Uuid $productId, Uuid $locationId, string $counted, ?Uuid $actorUserId, ?NamedLot $named): StockMovement
    {
        [$product, $location] = $this->trackedAt($company, $productId, $locationId);
        $lot = $this->lotFor($product, $named, true);
        $this->movements->lockStockOf($product->getId(), $location->getId());
        $movement = StockMovement::count($product, $location, $counted, $this->movements->onHand($productId, $locationId, $lot?->getId()), $actorUserId, $this->clock->now(), $lot);
        $this->inStockOnce($movement);
        $this->save($movement);
        $this->liveChanges->stage(new LiveChange('stock', $productId, 'stock.counted', $actorUserId, $company->getId()));

        return $movement;
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
            // Inside one establishment a move changes no total, but one into quarantine takes goods off what can be sold.
            $this->alerts?->raise([$out, $in]);
            // One move is one change: the stock of this product moved, once.
            $this->liveChanges->stage(new LiveChange('stock', $productId, 'stock.moved', $actorUserId, $company->getId()));

            return [$out, $in];
        });
    }

    /**
     * Goods written off with the reason they left under (§ 7 2026-09-19 23:25). The source stock is locked before it
     * is read, as a move does, so two losses cannot both find enough there. An expired lot may be written off: that
     * is the reason it exists, and no one is let to deliver it by that.
     *
     * @throws InvalidStockMovement
     */
    public function writeOff(Company $company, Uuid $productId, Uuid $locationId, string $quantity, StockLossReason $reason, ?string $note, ?Uuid $actorUserId, ?NamedLot $named = null): StockMovement
    {
        return $this->transactions->run(function () use ($company, $productId, $locationId, $quantity, $reason, $note, $actorUserId, $named): StockMovement {
            [$product, $location] = $this->trackedAt($company, $productId, $locationId);
            $lot = $this->lotFor($product, $named, false);
            $this->movements->lockStockOf($product->getId(), $location->getId());
            $movement = StockMovement::loss($product, $location, $quantity, $reason, $note, $actorUserId, $this->clock->now(), $lot);
            $onHand = $this->movements->onHand($productId, $locationId, $lot?->getId());
            if (1 === new Number(new Number($movement->getQuantity())->mul(-1)->value)->compare(new Number($onHand))) {
                throw new InvalidStockMovement('quantity', \sprintf('Only %s is at that location.', $onHand));
            }
            $this->movements->save($movement);
            $this->alerts?->raise([$movement]);
            $this->liveChanges->stage(new LiveChange('stock', $productId, 'stock.written_off', $actorUserId, $company->getId()));

            return $movement;
        });
    }

    /**
     * Lets an expired lot leave after all (docs/SPEC.md § 7, 2026-09-23 02:40): a person looked at the goods and
     * decided, and the lot keeps who and when. Its day is the company's.
     *
     * @throws StockLotNotFound
     * @throws InvalidStockMovement
     */
    public function release(Company $company, Uuid $lotId, Uuid $actorUserId): StockLot
    {
        return $this->transactions->run(function () use ($company, $lotId, $actorUserId): StockLot {
            $lot = $this->lots->ofIdInCompany($lotId, $company->getId()) ?? throw new StockLotNotFound();
            if ($lot->release($actorUserId, $this->clock->now()->setTimezone(new \DateTimeZone($company->getTimezone())))) {
                $this->lots->save($lot);
                $this->liveChanges->stage(new LiveChange('stock', $lot->getProduct()->getId(), 'stock.lot_released', $actorUserId, $company->getId()));
            }

            return $lot;
        });
    }

    /**
     * What the stock of each product is worth, at the weighted average of what came in; what has no recorded cost is
     * estimated at the product's cost price now.
     *
     * @return list<StockValue>
     */
    public function valuation(Company $company): array
    {
        $values = $this->movements->valuation($company->getId());
        $costs = [];
        foreach ($this->products->ofIdsInCompany(array_map(static fn (StockValue $value): Uuid => $value->productId, $values), $company->getId()) as $product) {
            $costs[$product->getId()->toRfc4122()] = $product->getDetails()->costPrice;
        }

        return array_map(static fn (StockValue $value): StockValue => $value->estimatedAt($costs[$value->productId->toRfc4122()] ?? null), $values);
    }

    /**
     * What is on hand of each product asked for, wherever it is; products of another company are never counted.
     *
     * @param list<Uuid> $productIds
     *
     * @return array<string, numeric-string> by the product's id
     */
    public function totalsOf(Company $company, array $productIds): array
    {
        return $this->movements->totalsOf($company->getId(), $productIds);
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
        return $this->movements->searchMovements($company->getId(), $this->withSublocations($company, $search), $page);
    }

    /**
     * A location picked stands for itself and every location under it (docs/SPEC.md § 7, row 197): the site lists what
     * moved on its racks. The list and its file both ask through here. A company's locations are read whole, as the
     * location screens read them; one of another company stands for itself alone, which matches no row of this one.
     */
    private function withSublocations(Company $company, StockMovementSearch $search): StockMovementSearch
    {
        if ([] === $search->locations) {
            return $search;
        }
        $parents = [];
        foreach ($this->locations->ofCompany($company->getId()) as $location) {
            $parents[$location->getId()->toRfc4122()] = $location->getParent()?->getId()->toRfc4122();
        }

        return $search->withLocations(Tree::withDescendants($search->locations, $parents));
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
