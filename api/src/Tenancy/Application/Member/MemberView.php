<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Member;

/** One member of a company, as the member list shows them. */
final readonly class MemberView
{
    public const string JOINED = 'joined';
    public const string INVITED = 'invited';
    /** An invitation whose link has run out and that nobody used: kept a while in the list, to be sent again. */
    public const string EXPIRED = 'expired';

    public function __construct(
        public ?string $userId,
        public string $email,
        public ?string $displayName,
        public string $role,
        public ?string $joinedAt,
        public string $status = self::JOINED,
        /** The invitation an invited row stands for, which the screen withdraws or sends again; null on a member's row. */
        public ?string $invitationId = null,
        /** Until when an invitation's link can be used; null on a member's row. */
        public ?string $expiresAt = null,
        /** Until when wrong guesses keep the member's password locked; null when they do not now, and on an invited row. */
        public ?string $passwordLockedUntil = null,
    ) {
    }
}
