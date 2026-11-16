<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Support;

/**
 * The windows an owner may open the company to support for, in hours, and the one the page offers first: the
 * operator's (app.support.windows_hours, app.support.default_window_hours, SupportWindowBounds), never past the
 * three days ruled.
 */
final readonly class SupportWindows
{
    /** @param list<int> $offered */
    public function __construct(public array $offered, public int $first)
    {
    }
}
