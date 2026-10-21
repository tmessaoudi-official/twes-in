<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Records;

use App\Module\Customers\Infrastructure\ApiPlatform\Security\CustomerPermission;
use App\Module\Customers\Infrastructure\Module\CustomersModule;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\MergedAway;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;
use App\Records\Application\RecordSubject;

/**
 * A customer is deleted while nothing names it, with its contacts and settings, and merged into another, by whoever may
 * remove customers; whoever writes them restores it. Merged, it stays as the kept one's alias for what was sent or issued to it. Two
 * customers registered under different numbers, taxed under different regimes, or one a business and the other a
 * person, are not the same customer and are not merged.
 */
final readonly class CustomerRecords implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [new RecordSubject('customer', CustomerPermission::WRITE, CustomersModule::KEY, ['number', 'name'], MergedAway::Aliased, ['identifiers', 'tax_regime_id', 'kind'], removePermission: CustomerPermission::REMOVE)];
    }

    public function references(): array
    {
        return [
            RecordReference::uses('customer', 'merged_into_id', 'customer', RecordMerge::Moves, 'A customer merged away stands for the customer it was merged into; merged on, for the customer kept.'),
            RecordReference::owned('contact', 'customer_id', 'customer', RecordMerge::Moves, 'A contact is someone at the customer: it goes and comes back with it, and merged, it is someone at the customer kept.'),
        ];
    }
}
