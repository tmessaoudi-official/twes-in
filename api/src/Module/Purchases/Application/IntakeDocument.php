<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

use Symfony\Component\Uid\Uuid;

/** Where a receipt's goods came from, as the stock keeps it beside each movement: the vendor, its delivery's number and the day. */
final readonly class IntakeDocument
{
    public function __construct(public Uuid $vendorId, public ?string $supplierReference, public \DateTimeImmutable $receivedOn)
    {
    }
}
