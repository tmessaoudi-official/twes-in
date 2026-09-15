<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

/** What the accept page may show before anybody has signed in: enough context, and no more. */
final readonly class InvitationSummary
{
    public function __construct(
        public string $email,
        public string $companyName,
        public string $roleName,
        public string $expiresAt,
        public bool $hasAccount = false,
    ) {
    }
}
