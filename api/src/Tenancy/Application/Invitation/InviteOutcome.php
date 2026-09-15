<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

/** What inviting an address did: always an invitation, which names nobody until it is accepted. */
final readonly class InviteOutcome
{
    public function __construct(
        public string $email,
        public string $roleName,
    ) {
    }
}
