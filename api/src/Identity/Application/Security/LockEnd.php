<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Security;

/** When an account's lock ends, as its owner reads it: the moment in their zone, the zone, and their order of a day. */
final readonly class LockEnd
{
    public function __construct(
        /** `Y-m-d H:i`, already in `$zone` */
        public string $moment,
        public string $zone,
        /** The person's `presentation.date-format`, `auto` for their language's. */
        public string $dateFormat = 'auto',
    ) {
    }
}
