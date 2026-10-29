<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\RealtimeCuts;
use Symfony\Component\Uid\Uuid;

/** Keeps which connections a unit test's use case asked to close: whole accounts, and single sessions. */
final class RecordingRealtimeCuts implements RealtimeCuts
{
    /** @var list<string> */
    public array $accounts = [];

    /** @var list<array{string, string}> */
    public array $sessions = [];

    public function cut(Uuid $userId): void
    {
        $this->accounts[] = $userId->toRfc4122();
    }

    public function cutSession(Uuid $userId, Uuid $sessionId): void
    {
        $this->sessions[] = [$userId->toRfc4122(), $sessionId->toRfc4122()];
    }
}
