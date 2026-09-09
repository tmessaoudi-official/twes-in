<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

/** Who they now are, where they now belong, and whether this actually set a password. */
final readonly class AcceptOutcome
{
    public function __construct(
        public string $userId,
        public string $companyId,
        public string $companyName,
        public string $roleName,
        public bool $passwordSet,
    ) {
    }
}
