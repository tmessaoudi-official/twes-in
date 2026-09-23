<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\DeliveryNotes\Domain\DeliveredQuantity;
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
 * said, because no unit converts into another. A cancelled note returns exactly what its validation took out, whatever
 * the tracking or the module say since. Both are idempotent: an event handled twice moves nothing the second time.
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
            // Which lot leaves is the delivery's to pick, first to expire first, and that comes in its own step
            // (docs/SPEC.md § 7, 2026-09-23 02:40, L2): until then such a line moves nothing and says so.
            if (ProductTracking::None !== $product->getTracking()) {
                $skipped[] = \sprintf('%s is tracked by %s, so its line moved no stock: record which one left as a move or a count', $product->getReference(), ProductTracking::Serial === $product->getTracking() ? 'serial number' : 'lot');
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
            $key = $product->getId()->toRfc4122();
            $out[$key] = [$product, ($out[$key][1] ?? new Number(0))->add($line->quantity)];
        }
        if ([] === $out) {
            return $skipped;
        }
        $establishment = $this->establishments->ofIdInCompany($establishmentId, $companyId);
        if (null === $establishment) {
            return [...$skipped, 'its establishment is not one of its company\'s, so it moved no stock'];
        }

        $now = $this->clock->now();
        ksort($out);
        $this->transactions->run(function () use ($establishment, $out, $deliveryNoteId, $now): void {
            $location = $this->locations->defaultOf($establishment);
            foreach ($out as $delivered) {
                $this->movements->lockStockOf($delivered[0]->getId(), $location->getId());
            }
            $this->movements->save(...array_map(
                static fn (array $delivered): StockMovement => StockMovement::delivery($delivered[0], $location, $delivered[1]->value, $deliveryNoteId, $now),
                array_values($out),
            ));
        });

        return $skipped;
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
