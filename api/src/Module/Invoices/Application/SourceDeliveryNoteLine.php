<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use Symfony\Component\Uid\Uuid;

/**
 * What a delivery note line delivered: its product, if it names one, its quantity and the decimals its unit counts in,
 * and whether its note was cancelled since, which leaves nothing of it to invoice.
 */
final readonly class SourceDeliveryNoteLine
{
    public function __construct(public ?Uuid $productId, public string $quantity, public int $unitDecimals, public bool $cancelled = false)
    {
    }
}
