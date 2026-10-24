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

    public function tell(Uuid $userId, SecurityChange $change, ?string $passkeyName = null): void
    {
        $this->told[] = $change;
    }
}
