<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

/** What the notes give the invoice drafted from them: the last day they delivered on and the reference they share. */
final readonly class InvoiceHeaderOfNotes
{
    public function __construct(public ?\DateTimeImmutable $supplyDate, public ?string $customerReference)
    {
    }
}
