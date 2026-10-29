<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Closes realtime connections once the unit of work of Transactions::run() that asked commits, never when it rolls
 * back: a session ended, so no screen of it should hear what changes meanwhile, and nobody else's is touched.
 */
interface RealtimeCuts
{
    /** Every connection of the account, each session's: all of them ended, or its password was locked. */
    public function cut(Uuid $userId): void;

    /** That session's connections alone: it signed out, or another of the account's ended it. */
    public function cutSession(Uuid $userId, Uuid $sessionId): void;
}
