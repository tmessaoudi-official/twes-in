<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

use Symfony\Component\Uid\Uuid;

/** What one line of a validated note hands over: a quantity with three decimals in a unit, of a product or of none. */
final readonly class DeliveredQuantity
{
    public function __construct(public ?Uuid $productId, public string $quantity, public Uuid $unitId)
    {
    }
}
