<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

/** What the person on the other end of the link supplies. */
final readonly class AcceptRequest
{
    public function __construct(
        public string $rawToken,
        public ?string $displayName,
        public ?string $plainPassword,
    ) {
    }
}
