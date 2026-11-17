<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Application;

/**
 * A choice a report offers in how it reads, named in its address so its screen, its file, a row's documents and the period
 * compared all read the same: what it reads when none is asked is its first choice, today's reading.
 */
final readonly class ReportOption
{
    /** @param non-empty-list<string> $choices */
    public function __construct(public string $name, public array $choices)
    {
    }
}
