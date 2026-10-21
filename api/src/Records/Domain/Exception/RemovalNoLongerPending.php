<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Domain\Exception;

/**
 * A delete or a merge that can no longer be undone, and why: its day is over, or it was undone already, which a second
 * « Rétablir » (a double click, a retry after a lost answer) finds and must not call final.
 */
final class RemovalNoLongerPending extends \DomainException
{
    public const string FINAL = 'removal_final';
    public const string RESTORED = 'removal_restored';
    public const string MERGE_UNDONE = 'merge_undone';

    /** @param self::FINAL|self::RESTORED|self::MERGE_UNDONE $reason */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
