<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Domain;

/** Where a subscription stands at one instant, and what that lets its company do. */
final readonly class Standing
{
    public function __construct(
        public Stage $stage,
        public Access $access,
        /** The last instant a trial or a paid period covers. */
        public \DateTimeImmutable $coveredUntil,
        public \DateTimeImmutable $graceEndsAt,
        /** Whole days left before the stage changes, counted up; null once unpaid. */
        public ?int $daysLeft,
    ) {
    }
}
