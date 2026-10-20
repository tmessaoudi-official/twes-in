<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\Records;

use App\Module\Vendors\Infrastructure\ApiPlatform\Security\VendorPermission;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordSubject;

/** A vendor is deleted while nothing names it, restored, and merged into another; whoever writes vendors does it. */
final readonly class VendorRecords implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [new RecordSubject('vendor', VendorPermission::WRITE)];
    }

    public function references(): array
    {
        return [];
    }
}
