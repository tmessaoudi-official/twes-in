<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataHealth\Application;

/** One check as it ran: what it found and how long it read for, which the console prints to watch it grow. */
final readonly class CheckRun
{
    /** @param list<DataFinding> $findings */
    public function __construct(
        public string $check,
        public array $findings,
        public float $seconds,
    ) {
    }
}
