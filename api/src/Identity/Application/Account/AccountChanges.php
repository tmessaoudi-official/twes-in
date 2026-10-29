<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Symfony\Component\Uid\Uuid;

/**
 * What an account's row shows where it is listed, its password lock and whether it may sign in, changes outside any
 * company: by a guess, the operator, the mailed link. The audit says it to its actor alone, so the change is said again
 * here to every company the account belongs to, whose members list hears it, and to the platform's accounts, by kind,
 * record and action alone. An account in no company is said to its own tabs, which the platform hears all the same.
 */
final readonly class AccountChanges
{
    public const string KIND = 'account';
    /** The guess that set the lock on a password. */
    public const string LOCKED = 'account.locked';

    public function __construct(private MembershipRepository $memberships, private LiveChanges $changes)
    {
    }

    public function said(Uuid $userId, string $action): void
    {
        $memberships = $this->memberships->ofUsers([$userId]);
        foreach ($memberships as $membership) {
            $this->changes->stage(new LiveChange(self::KIND, $userId, $action, null, $membership->getCompany()->getId()));
        }
        if ([] === $memberships) {
            $this->changes->stage(new LiveChange(self::KIND, $userId, $action, null, null, forUserId: $userId));
        }
    }
}
