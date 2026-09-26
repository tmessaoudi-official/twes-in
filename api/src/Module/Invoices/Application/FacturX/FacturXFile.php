<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\FacturX;

/** A Factur-X file as it is handed over: its name and its bytes. */
final readonly class FacturXFile
{
    public function __construct(public string $fileName, public string $contents)
    {
    }
}
