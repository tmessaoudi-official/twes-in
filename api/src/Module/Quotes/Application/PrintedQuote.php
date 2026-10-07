<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

/** A quote's PDF and the name it is downloaded under. */
final readonly class PrintedQuote
{
    public function __construct(public string $fileName, public string $contents)
    {
    }
}
