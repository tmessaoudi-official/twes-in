<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\Records;

use App\Module\Purchases\Domain\ValueObject\PurchaseOrderStatus;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;

/**
 * An order names its vendor, and its lines their products, by id alone, no foreign key crossing the modules. A sent
 * order printed the vendor it went to and stays with it; a draft moves to the vendor kept.
 */
final readonly class PurchaseRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [];
    }

    public function references(): array
    {
        return [
            RecordReference::uses('purchase_order', 'vendor_id', 'vendor', RecordMerge::Moves, 'An order names the vendor it is placed with.')
                ->movingOnlyWhere('status', [PurchaseOrderStatus::Draft->value]),
            RecordReference::uses('purchase_order_line', 'product_id', 'product', RecordMerge::Moves, 'An order line names the product ordered; merged, a draft orders the product kept, a sent order what the vendor was sent.')
                ->through('purchase_order', 'purchase_order_id')
                ->movingOnlyWhere('status', [PurchaseOrderStatus::Draft->value]),
        ];
    }
}
