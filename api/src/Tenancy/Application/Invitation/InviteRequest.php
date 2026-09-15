<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

use Symfony\Component\Uid\Uuid;

/** Offer one address a place in one company: a mailed invitation, whether or not the address has an account. */
final readonly class InviteRequest
{
    public function __construct(
        public Uuid $companyId,
        public string $email,
        public string $roleName,
    ) {
    }
}
