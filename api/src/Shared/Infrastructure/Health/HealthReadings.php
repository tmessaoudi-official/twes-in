<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

/** What the deep health read from the server at one moment: counts, ages and a share, never a record's contents. */
final readonly class HealthReadings
{
    public function __construct(
        public int $failedMessages,
        /** How long the oldest message due for the worker has waited; null when none is waiting. */
        public ?int $oldestWaitingSeconds,
        /** The free share of the disk the stored files are on, 0 to 1. */
        public float $diskFree,
        public Archiving $archiving,
    ) {
    }
}
