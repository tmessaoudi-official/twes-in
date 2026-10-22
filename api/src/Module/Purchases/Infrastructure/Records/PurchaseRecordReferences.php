<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\Records;

use App\Module\Purchases\Domain\ValueObject\PurchaseOrderStatus;
use App\Module\Purchases\Infrastructure\ApiPlatform\Security\PurchaseOrderPermission;
use App\Module\Purchases\Infrastructure\Module\PurchasesModule;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;
use App\Records\Application\RecordSubject;

/**
 * An order names its vendor, and its lines their products, by id alone, no foreign key crossing the modules. A sent
 * order printed the vendor it went to and stays with it; a draft moves to the vendor kept.
 */
final readonly class PurchaseRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        // Only a draft is deleted: a sent order is what the vendor holds. A draft has no number yet, so it reads as the
        // vendor it is placed with.
        return [new RecordSubject('purchase_order', PurchaseOrderPermission::WRITE, PurchasesModule::KEY, ['number'], whereColumn: 'status', whereValues: [PurchaseOrderStatus::Draft->value], labelTable: 'vendor', labelKey: 'vendor_id', labelTableColumns: ['number', 'name'])];
    }

    public function references(): array
    {
        return [
            RecordReference::owned('purchase_order_line', 'purchase_order_id', 'purchase_order', RecordMerge::Ignored, 'A line is part of its draft: it goes with it and comes back with it.'),
            RecordReference::uses('goods_receipt', 'purchase_order_id', 'purchase_order', RecordMerge::Ignored, 'A receipt names the order it took goods in for; a draft has none.'),
            RecordReference::uses('goods_receipt_line', 'purchase_order_line_id', 'purchase_order', RecordMerge::Ignored, 'A receipt\'s line names the order line it took in.')
                ->below('purchase_order_line', 'purchase_order_id'),
            RecordReference::uses('purchase_order', 'vendor_id', 'vendor', RecordMerge::Moves, 'An order names the vendor it is placed with.')
                ->movingOnlyWhere('status', [PurchaseOrderStatus::Draft->value]),
            RecordReference::uses('purchase_order_line', 'product_id', 'product', RecordMerge::Moves, 'An order line names the product ordered; merged, a draft orders the product kept, a sent order what the vendor was sent.')
                ->through('purchase_order', 'purchase_order_id')
                ->movingOnlyWhere('status', [PurchaseOrderStatus::Draft->value]),
        ];
    }
}
