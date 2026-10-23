<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application\Delivery;

/** A delivery note of a quote as the quote shows it: its id, its number once validated, and its status. */
final readonly class QuoteDeliveryNote
{
    public function __construct(public string $id, public ?string $number, public string $status)
    {
    }
}
