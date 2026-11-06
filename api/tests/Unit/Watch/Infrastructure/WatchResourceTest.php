<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Watch\Infrastructure;

use App\Watch\Application\WatchItem;
use App\Watch\Infrastructure\ApiPlatform\Resource\WatchResource;
use App\Watch\Infrastructure\ApiPlatform\Resource\WatchRowResource;
use PHPUnit\Framework\TestCase;

final class WatchResourceTest extends TestCase
{
    public function testTheSummaryCountsEverySubjectsRowsAndCarriesNoRow(): void
    {
        $resource = WatchResource::of([
            ['kind' => 'invoices.late_customer', 'count' => 44139],
            ['kind' => 'stock.lot_expired', 'count' => 2],
        ], 3600);

        self::assertSame(44141, $resource->count, 'the count is every row of every subject');
        self::assertSame(['invoices.late_customer', 'stock.lot_expired'], array_column($resource->subjects, 'kind'), 'in the order given');
    }

    public function testAQuietCompanyHasNothingToWatch(): void
    {
        $resource = WatchResource::of([], 86400);

        self::assertSame(0, $resource->count);
        self::assertSame([], $resource->subjects);
        self::assertSame(86400, $resource->readAgainIn, 'in how many seconds to read it again');
    }

    public function testARowKeepsWhatIsToBeActedOn(): void
    {
        $row = WatchRowResource::of(new WatchItem('stock.lot_expired', 'product-1', ['lot' => 'L-1', 'days' => -3]));

        self::assertSame(['stock.lot_expired', 'product-1', ['lot' => 'L-1', 'days' => -3]], [$row->kind, $row->subjectId, $row->params]);
    }
}
