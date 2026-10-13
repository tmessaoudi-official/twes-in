<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Application;

/**
 * The only ways an address leaves the journal, which the application otherwise only adds to: each entry keeps what
 * happened and loses the address alone. The database does the work, with rights the application does not hold.
 */
interface JournalAddresses
{
    /** Replaces one person's address wherever the journal names it, once their account is deleted; how many entries. */
    public function erase(string $address): int;

    /**
     * Takes the address and the network address out of every failed sign-in older than so many months, never fewer
     * than twelve; how many entries.
     */
    public function purgeFailedSignIns(int $months): int;
}
