<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\Records;

use App\Module\Vendors\Infrastructure\ApiPlatform\Security\VendorPermission;
use App\Module\Vendors\Infrastructure\Module\VendorsModule;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\MergedAway;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;
use App\Records\Application\RecordSubject;

/**
 * A vendor is deleted while nothing names it, restored, and merged into another; whoever writes vendors does it.
 * Merged, it stays as the kept one's alias for what still names it, so a vendor merged earlier into it follows on to
 * the one kept. Two vendors registered under different numbers are two businesses and are not merged. A vendor's
 * default expense category is said here, the column being the vendors'.
 */
final readonly class VendorRecords implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [new RecordSubject('vendor', VendorPermission::WRITE, VendorsModule::KEY, ['number', 'name'], MergedAway::Aliased, ['identifiers'])];
    }

    public function references(): array
    {
        return [
            RecordReference::uses('vendor', 'merged_into_id', 'vendor', RecordMerge::Moves, 'A vendor merged away stands for the vendor it was merged into; merged on, for the vendor kept.'),
            RecordReference::uses('vendor', 'default_expense_category_id', 'expense_category', RecordMerge::Moves, 'A vendor\'s expenses are filed under its default category; merged, under the category kept.'),
        ];
    }
}
