<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\ProductReorderPointRepository;
use App\Module\Inventory\Domain\StockLocationKind;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementRepository;
use BcMath\Number;

/**
 * Tells the people who keep the stock what the movements just saved mean for them (docs/SPEC.md § 7, alerts rather than
 * reports): a count that found a difference, and a product whose stock in an establishment fell to its reorder point.
 * The second is told when the stock FALLS to the point, not while it stays under it, so a product sitting at its point
 * does not raise one alert for every movement after.
 */
final readonly class RaiseStockAlerts
{
    public function __construct(
        private StockMovementRepository $movements,
        private ProductReorderPointRepository $points,
        private TellStockKeepers $tell,
    ) {
    }

    /** @param list<StockMovement> $saved movements already stored, so the stock read back includes them */
    public function raise(array $saved): void
    {
        $falls = [];
        foreach ($saved as $movement) {
            $this->countedDifference($movement);
            // Goods in quarantine cannot be sold, so what falls is what can be: a move between two places that sell nets to
            // nothing inside its establishment, and one into quarantine takes what it puts aside.
            if (StockLocationKind::Quarantine === $movement->getLocation()->getKind()) {
                continue;
            }
            $key = $movement->getProduct()->getId()->toRfc4122().'|'.$movement->getLocation()->getEstablishment()->getId()->toRfc4122();
            $falls[$key] = [$movement, ($falls[$key][1] ?? new Number('0.000'))->add($movement->getQuantity())];
        }

        foreach ($falls as [$movement, $net]) {
            if (-1 === $net->compare(0)) {
                $this->fellToItsPoint($movement, $net);
            }
        }
    }

    private function countedDifference(StockMovement $movement): void
    {
        if (StockMovement::SOURCE_COUNT !== $movement->getSourceType() || 0 === new Number($movement->getQuantity())->compare(0)) {
            return;
        }
        $product = $movement->getProduct();
        $this->tell->countDifference($movement->getCompany()->getId(), $movement->getRecordedBy(), [
            'product_id' => $product->getId()->toRfc4122(),
            'reference' => $product->getReference(),
            'product' => $product->getDetails()->name,
            'location' => $movement->getLocation()->getCode(),
            'difference' => $movement->getQuantity(),
        ]);
    }

    private function fellToItsPoint(StockMovement $movement, Number $net): void
    {
        $product = $movement->getProduct();
        $establishment = $movement->getLocation()->getEstablishment();
        $point = $this->points->ofProductInEstablishment($product->getId(), $establishment->getId());
        $reorderAt = $point?->getQuantity();
        if (null === $point || null === $reorderAt || !is_numeric($reorderAt) || !$product->isActive()) {
            return;
        }
        $after = new Number($this->movements->sellableInEstablishment($product->getId(), $establishment->getId()));
        $before = $after->sub($net);
        $limit = new Number($reorderAt);
        if (1 === $before->compare($limit) && $after->compare($limit) <= 0) {
            $this->tell->low($movement->getCompany()->getId(), [
                'product_id' => $product->getId()->toRfc4122(),
                'reference' => $product->getReference(),
                'product' => $product->getDetails()->name,
                'establishment' => $establishment->getName(),
                'on_hand' => $after->value,
                'point' => $point->getQuantity(),
            ]);
        }
    }
}
