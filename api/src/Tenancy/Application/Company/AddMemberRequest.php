<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use Symfony\Component\Uid\Uuid;

/** Add one person, identified by address, to one company with one built-in role. */
final readonly class AddMemberRequest
{
    public function __construct(
        public Uuid $companyId,
        public string $email,
        public string $roleName,
    ) {
    }
}
