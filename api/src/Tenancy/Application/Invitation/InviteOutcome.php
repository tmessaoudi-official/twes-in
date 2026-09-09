<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

/** What happened: they were already known and simply joined, or an invitation went out. */
final readonly class InviteOutcome
{
    public function __construct(
        public bool $joined,
        public string $email,
        public string $roleName,
        public ?string $userId = null,
    ) {
    }
}
