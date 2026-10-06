<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\CustomerScreen;

use Symfony\Component\Uid\Uuid;

/**
 * The customer screen holding a sign-in (docs/SPEC.md § 7, 2026-10-06 19:44): kept with the sign-in itself, so every tab
 * of it is held, and read by whatever decides what the sign-in may still reach.
 */
interface CustomerScreenLock
{
    /** Holds this account's sign-in on the screen of this company. */
    public function lock(Uuid $userId, Uuid $companyId): void;

    /** The company whose screen holds this account's sign-in, or null while nothing holds it. */
    public function lockedFor(Uuid $userId): ?Uuid;

    public function release(): void;
}
