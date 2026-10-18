<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Purchases\Domain\Model\PurchaseOrder;

/**
 * What an order to a vendor comes to before tax: each line its quantity times its price, rounded at the currency's scale,
 * and the order the sum of its lines. One place, so the screen and the printed order never disagree by a rounding. A line
 * without a price comes to nothing yet, and the order then has no total.
 */
final readonly class PurchaseOrderTotals
{
    /**
     * @param list<string|null> $nets  each line's, in the order's order
     * @param string|null       $total null while a line has no price, or the order no line
     */
    private function __construct(public array $nets, public ?string $total)
    {
    }

    public static function of(PurchaseOrder $order, int $scale): self
    {
        $nets = [];
        foreach ($order->getLines() as $line) {
            $price = $line->getUnitPriceNet();
            $nets[] = null === $price ? null : Decimal::format(Decimal::round(Decimal::of($line->getQuantity())->mul(Decimal::of($price)), $scale), $scale);
        }
        $priced = array_values(array_filter($nets, static fn (?string $net): bool => null !== $net));

        return new self($nets, [] === $nets || \count($priced) !== \count($nets)
            ? null
            : Decimal::format(Decimal::sum(array_map(static fn (string $net): \BcMath\Number => Decimal::of($net), $priced)), $scale));
    }
}
