<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Whether an account that no longer exists was deleted, as a person may ask, rather than never having been: a
 * notification raised about them before the deletion and published after it is then told to no one.
 */
interface DeletedAccounts
{
    public function wasDeleted(Uuid $userId): bool;
}
