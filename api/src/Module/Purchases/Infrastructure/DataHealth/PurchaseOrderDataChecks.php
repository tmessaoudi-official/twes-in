<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\DataHealth;

use App\DataHealth\Application\DeclaresDataChecks;
use App\DataHealth\Infrastructure\SeriesGaps;
use App\DataHealth\Infrastructure\UnrecordedDocuments;
use App\Module\Purchases\Application\ManagePurchaseOrders;
use App\Module\Purchases\Application\PurchaseOrderWorkflow;

/**
 * What the orders sent to vendors must still say: their numbers run without a gap in each series and period, and the
 * journal recorded each sending. An order stores no totals, which its lines give on every read.
 */
final readonly class PurchaseOrderDataChecks implements DeclaresDataChecks
{
    public const string NUMBERING = 'purchase_orders.numbering';
    public const string JOURNAL = 'purchase_orders.journal';

    public function __construct(private SeriesGaps $series, private UnrecordedDocuments $unrecorded)
    {
    }

    public function dataChecks(): array
    {
        return [self::NUMBERING, self::JOURNAL];
    }

    public function findings(string $check): iterable
    {
        return match ($check) {
            self::NUMBERING => $this->series->findings(self::NUMBERING, \sprintf("SELECT company_id, establishment_id, '%s' AS document_type, number, issue_date, id FROM purchase_order", PurchaseOrderWorkflow::DOCUMENT_TYPE)),
            self::JOURNAL => $this->unrecorded->findings(self::JOURNAL, 'SELECT company_id, id, number FROM purchase_order', ManagePurchaseOrders::ENTITY_TYPE, PurchaseOrderWorkflow::SENT),
            default => throw new \LogicException(\sprintf('No purchase order data check is named %s.', $check)),
        };
    }
}
