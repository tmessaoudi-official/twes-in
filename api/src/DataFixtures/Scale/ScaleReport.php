<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataFixtures\Scale;

/** What a generation run left behind: the copies of the base graph in place, the company's invoice rows, and whether a bounded run stopped early. */
final readonly class ScaleReport
{
    public function __construct(public int $copies, public int $wanted, public int $invoices, public bool $stopped)
    {
    }
}
