<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Delivery;

/** A delivery note of an invoice as the invoice shows it: its id, its number once validated, and its status. */
final readonly class InvoiceDeliveryNote
{
    public function __construct(public string $id, public ?string $number, public string $status)
    {
    }
}
