<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\Records;

use App\Module\Expenses\Domain\ValueObject\ExpenseStatus;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;

/**
 * An expense names its vendor. A recorded expense is in the books and keeps no copy of the vendor, so a merge leaves it
 * naming the vendor that went, which stays as the kept one's alias; a draft moves to the vendor kept.
 */
final readonly class ExpenseRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [];
    }

    public function references(): array
    {
        return [
            RecordReference::uses('expense', 'vendor_id', 'vendor', RecordMerge::Moves, 'An expense names the vendor it was bought from.')
                ->movingOnlyWhere('status', [ExpenseStatus::Draft->value])
                ->transactingAt('updated_at'),
        ];
    }
}
