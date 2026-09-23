<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

/** A lot as a movement names it: the code on the goods, and the date they are used by when the label gives one. */
final readonly class NamedLot
{
    public function __construct(public string $code, public ?\DateTimeImmutable $expiresOn = null)
    {
    }
}
