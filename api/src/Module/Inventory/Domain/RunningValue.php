<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use BcMath\Number;

/**
 * A product's valued stock as its movements leave it, one after the other: the quantity that carries a cost and what
 * that quantity is worth (docs/SPEC.md § 7, the weighted average). Stock may go below nothing; the goods that come in
 * to fill that hole then set the worth of what is on the shelf at their own cost, since the units that were missing
 * were never there to be worth anything.
 */
final readonly class RunningValue
{
    /**
     * @param numeric-string $quantity the quantity of the movements that carry a cost, signed
     * @param numeric-string $amount   what that quantity is worth, signed, seven decimals
     */
    public function __construct(public string $quantity, public string $amount)
    {
    }

    /** @param array{quantity: numeric-string, amount: numeric-string} $totals */
    public static function of(array $totals): self
    {
        return new self($totals['quantity'], $totals['amount']);
    }

    /** @return numeric-string|null what one unit is worth now, four decimals; the cost price when nothing is valued */
    public function average(?string $costPrice): ?string
    {
        return WeightedAverageCost::of($this->quantity, $this->amount, $costPrice);
    }

    /**
     * What has to be added to quantity times cost for goods coming in to lift the stock from nothing or less to
     * nothing or more, so that the stock is then worth what is on the shelf at that cost; null when nothing is.
     *
     * @param numeric-string $quantity signed
     * @param numeric-string $unitCost
     *
     * @return numeric-string|null seven decimals
     */
    public function revaluationFor(string $quantity, string $unitCost): ?string
    {
        $before = new Number($this->quantity);
        $moved = new Number($quantity);
        if (1 !== $moved->compare(0) || 1 === $before->compare(0) || -1 === $before->add($moved)->compare(0)) {
            return null;
        }
        $revaluation = new Number('0.0000000')->add($before->mul($unitCost))->sub($this->amount);

        return 0 === $revaluation->compare(0) ? null : $revaluation->value;
    }

    /**
     * The stock once this much more has moved at this cost.
     *
     * @param numeric-string $quantity signed
     * @param numeric-string $unitCost
     */
    public function after(string $quantity, string $unitCost): self
    {
        $amount = new Number('0.0000000')->add($this->amount)->add(new Number($quantity)->mul($unitCost))->add($this->revaluationFor($quantity, $unitCost) ?? '0');

        return new self(new Number($this->quantity)->add($quantity)->value, $amount->value);
    }

    /**
     * Values a movement in turn: one that came with no cost at the average it finds, and one that lifts the stock from
     * below nothing with its revaluation. A movement no cost is known for leaves the valued stock as it was.
     */
    public function take(StockMovement $movement): self
    {
        if (null === $movement->getUnitCost()) {
            $average = $this->average($movement->getProduct()->getDetails()->costPrice);
            if (null !== $average) {
                $movement->valuedAt($average);
            }
        }
        $cost = $movement->getUnitCost();
        if (null === $cost) {
            return $this;
        }
        $revaluation = $this->revaluationFor($movement->getQuantity(), $cost);
        if (null !== $revaluation) {
            $movement->revaluedBy($revaluation);
        }

        return $this->after($movement->getQuantity(), $cost);
    }
}
