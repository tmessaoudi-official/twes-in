<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use Symfony\Component\Uid\Uuid;

/** A delivery note invoice lines were taken from: its id, its number, and which of the lines asked about are its own. */
final readonly class SourceDeliveryNote
{
    /** @param list<string> $lineIds */
    public function __construct(public Uuid $id, public ?string $number, public array $lineIds)
    {
    }
}
