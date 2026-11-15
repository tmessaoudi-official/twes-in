<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Member;

use Symfony\Component\Uid\Uuid;

/** A member's signed-in sessions as one company sees them, which the accounts' context keeps, ends, records and tells. */
interface MemberSessions
{
    /** @return list<MemberSession> the member's live sessions that entered that company, newest use first */
    public function enteredIn(Uuid $userId, Uuid $companyId): array;

    /** Ends the one session named among those, or all of them when none is named; how many it ended. */
    public function endFor(Uuid $userId, ?Uuid $sessionId, Uuid $ownerUserId, Uuid $companyId): int;
}
