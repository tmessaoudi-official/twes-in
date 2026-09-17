<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Domain;

/**
 * What a company's subscription lets its members do, on top of what their role grants. It judges the permission a
 * request asks for, never the role's grants: an owner's role grants "*", which would otherwise pass every check.
 */
enum Access: string
{
    case Full = 'full';
    case ReadOnly = 'read_only';
    case Locked = 'locked';

    /** What stays open whatever the subscription says: the way out of an unpaid state. */
    public const array ALWAYS = ['subscription.read', 'subscription.pay'];

    public function permits(string $permission): bool
    {
        return match ($this) {
            self::Full => true,
            self::ReadOnly => str_ends_with($permission, '.read') || \in_array($permission, self::ALWAYS, true),
            self::Locked => \in_array($permission, self::ALWAYS, true),
        };
    }
}
