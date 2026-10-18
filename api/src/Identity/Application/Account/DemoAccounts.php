<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

use Symfony\Component\Uid\Uuid;

/**
 * Whether an account is a demo visitor's: the throwaway owner of a demo sandbox, made for it and taken away with it. It
 * has no password nor passkey, so it counts as having just proved itself, and it shows one address to every visitor.
 * Answered by Demo, which holds the sandboxes.
 */
interface DemoAccounts
{
    /** The address every demo account shows: the one it is stored under is unique to it, and means nothing to a visitor. */
    public const string SHOWN_AS = 'demo@example.com';

    public function isDemo(Uuid $userId): bool;

    /**
     * Every sandbox's owner, whatever its state: what the platform's operators never see. A few dozen at most, the
     * pool's size.
     *
     * @return list<Uuid>
     */
    public function all(): array;
}
