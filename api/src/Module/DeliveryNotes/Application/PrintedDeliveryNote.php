<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

final readonly class PrintedDeliveryNote
{
    public function __construct(public string $fileName, public string $contents)
    {
    }
}
