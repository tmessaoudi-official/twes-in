<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Closes a person's realtime connections once the unit of work of Transactions::run() that asked commits, never when
 * it rolls back: every session of theirs ended, so no screen of theirs should hear what changes meanwhile.
 */
interface RealtimeCuts
{
    public function cut(Uuid $userId): void;
}
