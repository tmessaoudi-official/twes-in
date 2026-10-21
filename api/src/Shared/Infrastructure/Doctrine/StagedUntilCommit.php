<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Holds what a unit of work said until DoctrineTransactions commits its outermost level, and forgets it when the unit
 * rolls back: what is told about a change (to open screens, or in the answer to whoever made it) is never told of one
 * that did not happen. Each level of a nested unit calls begin once and then commit or rollBack once.
 */
#[AutoconfigureTag(self::TAG)]
interface StagedUntilCommit
{
    public const string TAG = 'app.staged_until_commit';

    public function begin(): void;

    public function commit(): void;

    public function rollBack(): void;
}
