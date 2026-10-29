<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Member;

use Symfony\Component\Uid\Uuid;

/** The lock wrong guesses set on an account's password, which the accounts' context keeps, records and tells. */
interface PasswordLocks
{
    /** Lifted by an owner of that company for one of its members; nothing happens to a password no longer locked. */
    public function liftForMember(Uuid $userId, Uuid $ownerUserId, Uuid $companyId): void;
}
