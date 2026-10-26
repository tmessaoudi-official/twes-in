<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Approval;

/** An owner invitation nobody has used yet, as the platform shows it beside the company: open, or run out lately. */
final readonly class PlatformOwnerInvitationView
{
    public function __construct(
        public string $email,
        /** ISO 8601 */
        public string $expiresAt,
        public bool $expired,
    ) {
    }
}
