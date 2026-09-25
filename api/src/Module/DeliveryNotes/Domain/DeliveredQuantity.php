<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * What one line of a validated note hands over: a quantity with three decimals in a unit, of a product or of none, and
 * the lot or serial the line names, if it names one (docs/SPEC.md § 7, 2026-09-24 12:40 row 5).
 */
final readonly class DeliveredQuantity
{
    public function __construct(public ?Uuid $productId, public string $quantity, public Uuid $unitId, public ?string $lotCode = null)
    {
    }
}
