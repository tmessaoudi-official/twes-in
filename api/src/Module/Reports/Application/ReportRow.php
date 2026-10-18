<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Application;

/**
 * One row of a report: the key that makes it the same row in another period, one cell per column (a decimal string, an
 * integer, a day, a label, or null where there is nothing), and where its figures lead.
 */
final readonly class ReportRow
{
    /** @param array<string, string|int|null> $cells */
    public function __construct(public string $key, public array $cells, public ?ReportDrill $drill = null)
    {
    }
}
