<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Watch\Infrastructure;

use App\Watch\Application\WatchItem;
use App\Watch\Infrastructure\ApiPlatform\WatchResource;
use App\Watch\Infrastructure\ApiPlatform\WatchRowResource;
use PHPUnit\Framework\TestCase;

final class WatchResourceTest extends TestCase
{
    public function testTheSummaryCountsEverySubjectsRowsAndCarriesNoRow(): void
    {
        $resource = WatchResource::of([
            ['kind' => 'invoices.late_customer', 'count' => 44139],
            ['kind' => 'stock.lot_expired', 'count' => 2],
        ]);

        self::assertSame(44141, $resource->count, 'the count is every row of every subject');
        self::assertSame(['invoices.late_customer', 'stock.lot_expired'], array_column($resource->subjects, 'kind'), 'in the order given');
    }

    public function testAQuietCompanyHasNothingToWatch(): void
    {
        $resource = WatchResource::of([]);

        self::assertSame(0, $resource->count);
        self::assertSame([], $resource->subjects);
    }

    public function testARowKeepsWhatIsToBeActedOn(): void
    {
        $row = WatchRowResource::of(new WatchItem('stock.lot_expired', 'product-1', ['lot' => 'L-1', 'days' => -3]));

        self::assertSame(['stock.lot_expired', 'product-1', ['lot' => 'L-1', 'days' => -3]], [$row->kind, $row->subjectId, $row->params]);
    }
}
