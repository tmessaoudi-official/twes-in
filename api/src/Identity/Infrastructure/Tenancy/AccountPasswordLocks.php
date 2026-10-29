<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Tenancy;

use App\Identity\Application\Login\LiftPasswordLock;
use App\Identity\Application\Security\SecurityChange;
use App\Tenancy\Application\Member\PasswordLocks;
use Symfony\Component\Uid\Uuid;

/** The companies' context lifts a member's lock through the accounts' own use case, which records and tells it. */
final readonly class AccountPasswordLocks implements PasswordLocks
{
    public function __construct(private LiftPasswordLock $locks)
    {
    }

    public function liftForMember(Uuid $userId, Uuid $ownerUserId, Uuid $companyId): void
    {
        $this->locks->lift($userId, $ownerUserId, $companyId, SecurityChange::LockLiftedByOwner);
    }
}
