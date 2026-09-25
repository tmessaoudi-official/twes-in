<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\DeliveryNotes\Domain\DeliveredQuantity;
use App\Module\Inventory\Domain\LotOnHand;
use App\Module\Inventory\Domain\LotPicking;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementKind;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Module\Products\Domain\ProductRepository;
use App\Module\Products\Domain\ProductTracking;
use App\ModuleRegistry\Application\ModuleStates;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\EstablishmentRepository;
use BcMath\Number;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What a delivery note does to stock (docs/SPEC.md § 7, 2026-09-14: the goods leave with validation). A validated note
 * takes each product it delivers out of its establishment's default location, once per product, while the company has
 * inventory on and keeps stock of that product; a line counted in another unit than its product moves nothing and is
 * said, because no unit converts into another. A product tracked by lot or serial number leaves from its lots, the first
 * to expire first, and never from an expired lot nobody released; what no lot in date holds is said and not moved
 * (docs/SPEC.md § 7, 2026-09-23 02:40). A cancelled note returns exactly what its validation took out, to the lots it
 * took it from, whatever the tracking or the module say since. Both are idempotent: an event handled twice moves
 * nothing the second time.
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
    ) {
    }

    /**
     * @param list<DeliveredQuantity> $lines
     *
     * @return list<string> why a line of a product whose stock is kept moved nothing
     */
    public function validated(Uuid $deliveryNoteId, Uuid $companyId, Uuid $establishmentId, array $lines): array
    {
        if (!$this->modules->isEnabled($companyId, self::MODULE) || [] !== $this->movements->ofSource(StockMovement::SOURCE_DELIVERY_NOTE, $deliveryNoteId, $companyId)) {
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

        return $this->transactions->run(function () use ($establishment, $out, $deliveryNoteId, $now, $today, $skipped): array {
            $location = $this->locations->defaultOf($establishment);
            $products = [];
            foreach ($out as [$product]) {
                $products[$product->getId()->toRfc4122()] = $product;
            }
            ksort($products);
            foreach ($products as $product) {
                $this->movements->lockStockOf($product->getId(), $location->getId());
            }
            $written = [];
            $taking = [];
            foreach ($out as [$product, $quantity, $named]) {
                if (ProductTracking::None === $product->getTracking()) {
                    $written[] = StockMovement::delivery($product, $location, $quantity->value, $deliveryNoteId, $now);
                    continue;
                }
                // Read after the lock, as a count reads: what is picked is what no other delivery is taking. What an
                // earlier group of this note took is not on hand any more, though it is not saved yet.
                $onHand = self::less($this->movements->lotsAt($product->getId(), $location->getId()), $taking);
                $picked = null === $named
                    ? LotPicking::firstExpiring($onHand, $quantity->value, $today)
                    : LotPicking::named($onHand, $named, $quantity->value, $today);
                foreach ($picked->taken as [$lot, $taken]) {
                    $written[] = StockMovement::delivery($product, $location, $taken, $deliveryNoteId, $now, $lot);
                    $key = $lot->getId()->toRfc4122();
                    $taking[$key] = ($taking[$key] ?? new Number(0))->add($taken);
                }
                if (1 === new Number($picked->short)->compare(0)) {
                    $skipped[] = null === $named
                        ? \sprintf('%s of %s was in no lot in date at %s, so it moved no stock: receive it under its lot, or release an expired one', $picked->short, $product->getReference(), $location->getCode())
                        : self::namedShort($picked->short, $product->getReference(), $named, $location->getCode(), LotPicking::find($onHand, $named), $today);
                }
            }
            if ([] !== $written) {
                $this->movements->save(...$written);
            }

            return $skipped;
        });
    }

    /**
     * The lots on hand less what this note already takes from them.
     *
     * @param list<LotOnHand>       $onHand
     * @param array<string, Number> $taking by lot id
     *
     * @return list<LotOnHand>
     */
    private static function less(array $onHand, array $taking): array
    {
        return array_map(static function (LotOnHand $each) use ($taking): LotOnHand {
            $taken = $taking[$each->lot->getId()->toRfc4122()] ?? null;

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
