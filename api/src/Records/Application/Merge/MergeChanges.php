<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application\Merge;

use Symfony\Component\Uid\Uuid;

/**
 * A change a module's merge effects make to rows beyond pointing them elsewhere, noted under the merge like every link it
 * moves, so undoing the merge puts it back with them: a contact that was its customer's main one, moved under a customer
 * that has its own, is no longer the main one.
 */
interface MergeChanges
{
    /**
     * Sets the column of the company's rows given, a value as PostgreSQL reads it from text, noting what each held.
     *
     * @param list<Uuid> $rowIds
     */
    public function set(Uuid $companyId, Uuid $mergeId, string $table, string $column, array $rowIds, string $value): void;
}
