<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

final readonly class PrintedInvoice
{
    public function __construct(public string $fileName, public string $contents)
    {
    }
}
