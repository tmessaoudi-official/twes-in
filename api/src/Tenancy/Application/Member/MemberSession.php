<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Member;

use Symfony\Component\Uid\Uuid;

/** One browser a member is signed in on, as their company's owner is shown it: on what, from where, since and last when. */
final readonly class MemberSession
{
    public function __construct(
        public Uuid $id,
        /** The browser's User-Agent, as it sent it. */
        public string $device,
        public string $address,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $lastSeenAt,
    ) {
    }
}
