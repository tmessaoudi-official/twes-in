<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use Symfony\Component\Uid\Uuid;

/** What a delivery note line delivered: its product, if it names one, and its quantity. */
final readonly class SourceDeliveryNoteLine
{
    public function __construct(public ?Uuid $productId, public string $quantity)
    {
    }
}
