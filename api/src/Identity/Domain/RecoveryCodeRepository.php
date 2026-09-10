<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Domain;

interface RecoveryCodeRepository
{
    /** The unspent code with this hash, if the user has one. Lookup is by hash: the raw code is never stored. */
    public function unspent(User $user, string $codeHash): ?RecoveryCodeEntry;

    /** @return list<RecoveryCodeEntry> */
    public function ofUser(User $user): array;

    /** @param list<RecoveryCodeEntry> $entries */
    public function replaceAll(User $user, array $entries): void;

    /** Spending is a delete: `audit_log` already carries the story a used row could tell. */
    public function spend(RecoveryCodeEntry $entry): void;

    public function countFor(User $user): int;
}
