<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\DeliveryNotes\Domain\DeliveredQuantity;
use App\Module\Inventory\Domain\InvalidStockMovement;
use App\Module\Inventory\Domain\LotOnHand;
use App\Module\Inventory\Domain\LotPicking;
use App\Module\Inventory\Domain\ProductHomeLocation;
use App\Module\Inventory\Domain\ProductHomeLocationRepository;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLot;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementKind;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductRepository;
use App\Module\Products\Domain\ProductTracking;
use App\ModuleRegistry\Application\ModuleStates;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\EstablishmentRepository;
use BcMath\Number;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What a delivery note does to stock (the goods leave with validation). A validated note
 * takes each product it delivers out of the places the product lives in at its establishment, the main home first and
 * then the next in order, then the default location, never more than a place holds while another still holds goods and
 * what none holds going on the main place, while the company has inventory on and keeps stock of that product; a
 * product with no home leaves from the default location alone. A line counted in another unit than its product moves
 * nothing and is said, because no unit converts into another. A product tracked by lot or serial number leaves from its lots, the first
 * to expire first, and never from an expired lot nobody released; what no lot in date holds is said and not moved
 *. A cancelled note returns exactly what its validation took out, to the lots it
 * took it from, whatever the tracking or the module say since. Both are idempotent: an event handled twice moves
 * nothing the second time. An invoice's own product lines, those no delivery note handed over, leave through the same
 * path under their own source, which is why one class holds both.
 */
final readonly class MoveStockForDeliveryNotes
{
    public const string MODULE = 'inventory';

    public function __construct(
        private StockMovementRepository $movements,
        private ManageStockLocations $locations,
        private EstablishmentRepository $establishments,
        private ProductRepository $products,
        private KeepStock $stock,
        private ModuleStates $modules,
        private Transactions $transactions,
        private ClockInterface $clock,
        private ProductHomeLocationRepository $homes,
        private ?RaiseStockAlerts $alerts = null,
    ) {
    }

    /**
     * @param list<DeliveredQuantity> $lines
     *
     * @return list<string> why a line of a product whose stock is kept moved nothing
     */
    public function validated(Uuid $deliveryNoteId, Uuid $companyId, Uuid $establishmentId, array $lines): array
    {
        return $this->takeOut(StockMovement::SOURCE_DELIVERY_NOTE, $deliveryNoteId, $companyId, $establishmentId, $lines);
    }

    /**
     * What an issued invoice sold that no delivery note handed over leaves the same way a delivery does, once, and for
     * the same reasons a line may move nothing.
     *
     * @param list<DeliveredQuantity> $lines
     *
     * @return list<string> why a line of a product whose stock is kept moved nothing
     */
    public function invoiced(Uuid $invoiceId, Uuid $companyId, Uuid $establishmentId, array $lines): array
    {
        return $this->takeOut(StockMovement::SOURCE_INVOICE, $invoiceId, $companyId, $establishmentId, $lines);
    }

    /**
     * @param list<DeliveredQuantity> $lines
     *
     * @return list<string>
     */
    private function takeOut(string $sourceType, Uuid $sourceId, Uuid $companyId, Uuid $establishmentId, array $lines): array
    {
        if (!$this->modules->isEnabled($companyId, self::MODULE) || [] !== $this->movements->ofSource($sourceType, $sourceId, $companyId)) {
            return [];
        }

        $skipped = [];
        $out = [];
        foreach ($lines as $line) {
            $product = null === $line->productId ? null : $this->products->ofIdInCompany($line->productId, $companyId);
            if (null === $product || !$this->stock->tracked($product)) {
                continue;
            }
            if (!$product->getUnit()->getId()->equals($line->unitId)) {
                $skipped[] = \sprintf('a line of %s is counted in another unit than its stock, so it moved no stock', $product->getReference());
                continue;
            }
            if (!is_numeric($line->quantity)) {
                $skipped[] = \sprintf('a line of %s has no quantity, so it moved no stock', $product->getReference());
                continue;
            }
            // A named lot is its own group, taken as named; a line naming none joins its product's first-to-expire group.
            $lot = ProductTracking::None === $product->getTracking() ? null : $line->lotCode;
            $key = $product->getId()->toRfc4122().(null === $lot ? '' : "\0".$lot);
            $out[$key] = [$product, ($out[$key][1] ?? new Number(0))->add($line->quantity), $lot];
        }
        if ([] === $out) {
            return $skipped;
        }
        $establishment = $this->establishments->ofIdInCompany($establishmentId, $companyId);
        if (null === $establishment) {
            return [...$skipped, 'its establishment is not one of its company\'s, so it moved no stock'];
        }

        $now = $this->clock->now();
        // Named lots go first, in the note's order, so the first-to-expire pick cannot take the stock a line named; locks
        // are taken product by product in a fixed order all the same.
        $named = array_filter($out, static fn (string $key): bool => str_contains($key, "\0"), \ARRAY_FILTER_USE_KEY);
        $unnamed = array_diff_key($out, $named);
        ksort($unnamed);
        $out = [...$named, ...$unnamed];
        // The company's day decides which lots have expired: a lot used by today still leaves today, wherever the server is.
        $today = $now->setTimezone(new \DateTimeZone($establishment->getCompany()->getTimezone()));

        return $this->transactions->run(function () use ($establishment, $companyId, $out, $sourceType, $sourceId, $now, $today, $skipped): array {
            $default = $this->locations->defaultOf($establishment);
            $products = [];
            foreach ($out as [$product]) {
                $products[$product->getId()->toRfc4122()] = $product;
            }
            ksort($products);
            $places = $this->placesOf($products, $establishment->getId(), $companyId, $default);
            // Every place a product may leave from is locked, in one fixed order, before anything is read at any of them.
            $locks = [];
            foreach ($products as $productId => $product) {
                foreach ($places[$productId] as $place) {
                    $locks[$productId.' '.$place->getId()->toRfc4122()] = [$product->getId(), $place->getId()];
                }
            }
            ksort($locks);
            foreach ($locks as [$lockedProduct, $lockedPlace]) {
                $this->movements->lockStockOf($lockedProduct, $lockedPlace);
            }
            $written = [];
            $taking = [];
            foreach ($out as [$product, $quantity, $named]) {
                $list = $places[$product->getId()->toRfc4122()];
                if (ProductTracking::None === $product->getTracking()) {
                    foreach (self::shared($this->movements, $product, $list, $quantity) as [$place, $leaves]) {
                        $written[] = self::leaving($sourceType, $product, $place, $leaves->value, $sourceId, $now);
                    }
                    continue;
                }
                // Read after the lock, as a count reads: what is picked is what no other delivery is taking. What an
                // earlier group of this note took is not on hand any more, though it is not saved yet. Each place is
                // read in the order of the homes, so the main home empties before the next is touched.
                $short = $quantity->value;
                $first = null;
                foreach ($list as $place) {
                    if (1 !== new Number($short)->compare(0)) {
                        break;
                    }
                    $onHand = self::less($this->movements->lotsAt($product->getId(), $place->getId()), $taking, $place);
                    $first ??= $onHand;
                    $picked = null === $named
                        ? LotPicking::firstExpiring($onHand, $short, $today)
                        : LotPicking::named($onHand, $named, $short, $today);
                    foreach ($picked->taken as [$lot, $taken]) {
                        $written[] = self::leaving($sourceType, $product, $place, $taken, $sourceId, $now, $lot);
                        $key = $lot->getId()->toRfc4122().' '.$place->getId()->toRfc4122();
                        $taking[$key] = ($taking[$key] ?? new Number(0))->add($taken);
                    }
                    $short = $picked->short;
                }
                if (1 === new Number($short)->compare(0)) {
                    $skipped[] = null === $named
                        ? \sprintf('%s of %s was in no lot in date at %s, so it moved no stock: receive it under its lot, or release an expired one', $short, $product->getReference(), $list[0]->getCode())
                        : self::namedShort($short, $product->getReference(), $named, $list[0]->getCode(), LotPicking::find($first ?? [], $named), $today);
                }
            }
            if ([] !== $written) {
                $this->movements->save(...$written);
                $this->alerts?->raise($written);
            }

            return $skipped;
        });
    }

    /**
     * The places each product may leave from in this establishment, by the product's id, the main place first: its homes
     * in their order, then the default location, which holds what arrived before any home was named. A product with no
     * home leaves from the default location alone, as it always did.
     *
     * @param array<string, Product> $products by id
     *
     * @return array<string, non-empty-list<StockLocation>>
     */
    private function placesOf(array $products, Uuid $establishmentId, Uuid $companyId, StockLocation $default): array
    {
        $homes = $this->homes->ofProducts(array_values(array_map(static fn (Product $product): Uuid => $product->getId(), $products)), $companyId);
        $places = [];
        foreach (array_keys($products) as $productId) {
            $own = array_values(array_filter($homes[$productId] ?? [], static fn (ProductHomeLocation $home): bool => $home->getEstablishment()->getId()->equals($establishmentId)));
            usort($own, static fn (ProductHomeLocation $a, ProductHomeLocation $b): int => $a->getPosition() <=> $b->getPosition());
            $list = array_map(static fn (ProductHomeLocation $home): StockLocation => $home->getLocation(), $own);
            $places[$productId] = [] === $list
                ? [$default]
                : (array_any($list, static fn (StockLocation $place): bool => $place->getId()->equals($default->getId())) ? $list : [...$list, $default]);
        }

        return $places;
    }

    /**
     * What an untracked product's delivery takes from each place: what each holds, in the order of the places, never
     * more than it holds, until the delivery is covered. What no place holds goes on the main place with whatever it
     * gave, as one movement, so the shortage shows where the product lives and not at some shelf nobody chose. A
     * product with one place to leave from asks nothing of the stock: the whole delivery is its.
     *
     * @param non-empty-list<StockLocation> $places
     *
     * @return list<array{StockLocation, Number}>
     */
    private static function shared(StockMovementRepository $movements, Product $product, array $places, Number $quantity): array
    {
        if (1 === \count($places)) {
            return [[$places[0], $quantity]];
        }
        $left = $quantity;
        $by = [];
        foreach ($places as $at => $place) {
            if (1 !== $left->compare(0)) {
                break;
            }
            $held = new Number($movements->onHand($product->getId(), $place->getId()));
            if (1 !== $held->compare(0)) {
                continue;
            }
            $take = -1 === $left->compare($held) ? $left : $held;
            $by[$at] = $take;
            $left = $left->sub($take);
        }
        if (1 === $left->compare(0)) {
            $by[0] = ($by[0] ?? new Number(0))->add($left);
        }
        ksort($by);

        return array_map(static fn (int $at, Number $leaves): array => [$places[$at], $leaves], array_keys($by), $by);
    }

    /** @throws InvalidStockMovement */
    private static function leaving(string $sourceType, Product $product, StockLocation $location, string $quantity, Uuid $sourceId, \DateTimeImmutable $now, ?StockLot $lot = null): StockMovement
    {
        return StockMovement::SOURCE_INVOICE === $sourceType
            ? StockMovement::sale($product, $location, $quantity, $sourceId, $now, $lot)
            : StockMovement::delivery($product, $location, $quantity, $sourceId, $now, $lot);
    }

    /**
     * The lots on hand less what this note already takes from them.
     *
     * @param list<LotOnHand>       $onHand
     * @param array<string, Number> $taking by lot id and place id: a lot kept at two places is two stocks
     *
     * @return list<LotOnHand>
     */
    private static function less(array $onHand, array $taking, StockLocation $place): array
    {
        return array_map(static function (LotOnHand $each) use ($taking, $place): LotOnHand {
            $taken = $taking[$each->lot->getId()->toRfc4122().' '.$place->getId()->toRfc4122()] ?? null;

            return null === $taken ? $each : new LotOnHand($each->lot, new Number('0.000')->add(new Number($each->quantity)->sub($taken))->value);
        }, $onHand);
    }

    /** Why what a line named was not all taken: its lot is not at the location, expired unreleased, or holds less. */
    private static function namedShort(string $short, string $reference, string $code, string $location, ?LotOnHand $found, \DateTimeImmutable $today): string
    {
        if (null !== $found && !$found->lot->deliverableOn($today)) {
            return \sprintf('%s of %s lot %s moved no stock: that lot is expired and nobody released it, so release it or name another lot', $short, $reference, $code);
        }

        return \sprintf('%s of %s lot %s was not at %s, so it moved no stock: receive it under that lot, or name the lot handed over', $short, $reference, $code, $location);
    }

    /**
     * What a credit note returns of an invoice's sale goes back to the lots and the location the sale took it from,
     * worth what it left at, once per credit note, and never more than the sale took out less what earlier credit notes
     * of the same invoice already brought back. An invoice built from delivery notes sold what those notes took out, so
     * their movements count as its sale (docs/SPEC.md § 7, audit 2026-10-06 E-5). Like a cancelled note's return, it does
     * not ask whether the module or the product's tracking are still on: the sale is the proof that stock was kept.
     *
     * @param list<DeliveredQuantity> $lines           the product lines the person marked as returned
     * @param list<Uuid>              $deliveryNoteIds the delivery notes the corrected invoice was built from
     *
     * @return list<string> why a line brought back nothing, or less than it said
     */
    public function returned(Uuid $creditNoteId, Uuid $invoiceId, Uuid $companyId, array $lines, array $deliveryNoteIds = []): array
    {
        if ([] === $lines || [] !== $this->movements->ofSource(StockMovement::SOURCE_CREDIT_NOTE, $creditNoteId, $companyId)) {
            return [];
        }
        $sources = [$this->movements->ofSource(StockMovement::SOURCE_INVOICE, $invoiceId, $companyId)];
        foreach ($deliveryNoteIds as $deliveryNoteId) {
            $sources[] = $this->movements->ofSource(StockMovement::SOURCE_DELIVERY_NOTE, $deliveryNoteId, $companyId);
        }
        $sales = array_values(array_filter(array_merge(...$sources), static fn (StockMovement $movement): bool => StockMovementKind::Out === $movement->getKind()));

        $skipped = [];
        $asked = [];
        foreach ($lines as $line) {
            $product = null === $line->productId ? null : $this->products->ofIdInCompany($line->productId, $companyId);
            if (null === $product) {
                continue;
            }
            if (!$product->getUnit()->getId()->equals($line->unitId)) {
                $skipped[] = \sprintf('a line of %s is counted in another unit than its stock, so it returned no stock', $product->getReference());
                continue;
            }
            if (!is_numeric($line->quantity)) {
                $skipped[] = \sprintf('a line of %s has no quantity, so it returned no stock', $product->getReference());
                continue;
            }
            $lot = ProductTracking::None === $product->getTracking() ? null : $line->lotCode;
            $key = $product->getId()->toRfc4122().(null === $lot ? '' : "\0".mb_strtolower($lot));
            $asked[$key] = [$product, ($asked[$key][1] ?? new Number(0))->add($line->quantity), $lot];
        }
        if ([] === $asked) {
            return $skipped;
        }
        $now = $this->clock->now();

        return $this->transactions->run(function () use ($asked, $sales, $creditNoteId, $invoiceId, $companyId, $now, $skipped): array {
            // Locked as a sale is, so two credit notes of one invoice cannot both be told the same goods are left to return.
            $locks = [];
            foreach ($sales as $sale) {
                foreach ($asked as [$product]) {
                    if ($sale->getProduct()->getId()->equals($product->getId())) {
                        $locks[$sale->getProduct()->getId()->toRfc4122().' '.$sale->getLocation()->getId()->toRfc4122()] = [$sale->getProduct()->getId(), $sale->getLocation()->getId()];
                    }
                }
            }
            ksort($locks);
            foreach ($locks as [$productId, $locationId]) {
                $this->movements->lockStockOf($productId, $locationId);
            }

            // One product at one place and lot may have left through the invoice and several of its notes: a return
            // comes back once per place and lot, so what is left to return is counted over all of them.
            $soldAt = [];
            $left = [];
            foreach ($sales as $sale) {
                $key = self::stockKey($sale);
                $soldAt[$key][] = $sale;
                $left[$key] = ($left[$key] ?? new Number(0))->add(new Number($sale->getQuantity())->mul(-1));
            }
            foreach ($this->movements->ofReversing($invoiceId, $companyId) as $back) {
                $key = self::stockKey($back);
                if (isset($left[$key])) {
                    $left[$key] = $left[$key]->sub($back->getQuantity());
                }
            }

            $give = [];
            foreach ($asked as [$product, $quantity, $lot]) {
                $want = $quantity;
                $named = null === $lot ? '' : ' lot '.$lot;
                foreach ($soldAt as $key => [$sale]) {
                    if (!$sale->getProduct()->getId()->equals($product->getId()) || (null !== $lot && 0 !== strcasecmp((string) $sale->getLot()?->getCode(), $lot))) {
                        continue;
                    }
                    $take = 1 === $left[$key]->compare($want) ? $want : $left[$key];
                    if (1 !== $take->compare(0)) {
                        continue;
                    }
                    $give[$key] = ($give[$key] ?? new Number(0))->add($take);
                    $left[$key] = $left[$key]->sub($take);
                    $want = $want->sub($take);
                }
                if (1 === $want->compare(0)) {
                    $skipped[] = \sprintf('%s of %s%s was not sold by this invoice or its delivery notes, or had already been returned, so it returned no stock', new Number('0.000')->add($want)->value, $product->getReference(), $named);
                }
            }

            if ([] !== $give) {
                $this->movements->save(...array_map(
                    static fn (string $key): StockMovement => StockMovement::returnOfSales($soldAt[$key], new Number('0.000')->add($give[$key])->value, $creditNoteId, $invoiceId, $now),
                    array_keys($give),
                ));
            }

            return $skipped;
        });
    }

    /** What a sale and its returns share: the product, the place and the lot its goods are counted under. */
    private static function stockKey(StockMovement $movement): string
    {
        return $movement->getProduct()->getId()->toRfc4122().' '.$movement->getLocation()->getId()->toRfc4122().' '.($movement->getLot()?->getId()->toRfc4122() ?? '-');
    }

    public function cancelled(Uuid $deliveryNoteId, Uuid $companyId): void
    {
        $written = $this->movements->ofSource(StockMovement::SOURCE_DELIVERY_NOTE, $deliveryNoteId, $companyId);
        if (array_any($written, static fn (StockMovement $movement): bool => StockMovementKind::In === $movement->getKind())) {
            return;
        }
        $taken = array_values(array_filter($written, static fn (StockMovement $movement): bool => StockMovementKind::Out === $movement->getKind()));
        if ([] === $taken) {
            return;
        }

        $now = $this->clock->now();
        $this->transactions->run(function () use ($taken, $now): void {
            $this->movements->save(...array_map(static fn (StockMovement $delivery): StockMovement => StockMovement::returnOf($delivery, $now), $taken));
        });
    }
}
