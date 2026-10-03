<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * What one product line of an issued invoice sold: a quantity in a unit, and the lot or serial the line names, if it
 * names one. Whether the product keeps stock is the stock context's to say, so a service line is named here too.
 */
final readonly class InvoicedQuantity
{
    public function __construct(public Uuid $productId, public string $quantity, public Uuid $unitId, public ?string $lotCode = null)
    {
    }
}
