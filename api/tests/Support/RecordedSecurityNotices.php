<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Identity\Application\Security\SecurityChange;
use App\Identity\Application\Security\SecurityNotices;
use Symfony\Component\Uid\Uuid;

/** Keeps the notices a use case asked for, for a unit test to read. */
final class RecordedSecurityNotices implements SecurityNotices
{
    /** @var list<SecurityChange> */
    public array $told = [];

    /** @var list<Uuid> the account each notice was for, in the same order */
    public array $userIds = [];

    /** @var list<\DateTimeImmutable> the end of each lock told of */
    public array $until = [];

    public function tell(Uuid $userId, SecurityChange $change, ?string $passkeyName = null, ?\DateTimeImmutable $until = null): void
    {
        $this->told[] = $change;
        $this->userIds[] = $userId;
        if (null !== $until) {
            $this->until[] = $until;
        }
    }
}
