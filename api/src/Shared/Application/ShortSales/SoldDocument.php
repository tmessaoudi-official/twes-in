<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\ShortSales;

use Symfony\Component\Uid\Uuid;

/** The document a sale takes its stock out for: a validated delivery note or an issued invoice, by id and number. */
final readonly class SoldDocument
{
    public function __construct(public bool $invoice, public Uuid $id, public string $number)
    {
    }
}
