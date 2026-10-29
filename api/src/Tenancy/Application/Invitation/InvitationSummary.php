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
        /** the operator refused the company before it was ever opened: joining it opens nothing */
        public bool $companyRefused = false,
        /** the operator suspended the company once open: its members cannot work in it until it is opened again */
        public bool $companySuspended = false,
        /** the operator's reason for either, null when they gave none or the company is open */
        public ?string $companyReason = null,
    ) {
    }
}
