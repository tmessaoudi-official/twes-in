<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

/** One member of a company, as the member list shows them. */
final readonly class MemberView
{
    public const string JOINED = 'joined';
    public const string INVITED = 'invited';

    public function __construct(
        public ?string $userId,
        public string $email,
        public ?string $displayName,
        public string $role,
        public ?string $joinedAt,
        public string $status = self::JOINED,
    ) {
    }
}
