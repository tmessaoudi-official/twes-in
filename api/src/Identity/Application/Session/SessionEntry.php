<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Session;

use App\Identity\Domain\UserSession;

/** A session on the person's list, and whether it is the one asking. */
final readonly class SessionEntry
{
    public function __construct(public UserSession $session, public bool $current)
    {
    }
}
