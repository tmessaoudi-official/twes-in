<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Watch\Infrastructure;

use App\Watch\Application\WatchItem;
use App\Watch\Infrastructure\ApiPlatform\WatchResource;
use PHPUnit\Framework\TestCase;

final class WatchResourceTest extends TestCase
{
    public function testACapOnOneKindNeverHidesAnotherKind(): void
    {
        $late = array_map(
            static fn (int $n): WatchItem => new WatchItem('invoices.late_customer', \sprintf('customer-%d', $n), ['invoices' => 1]),
            range(1, WatchResource::MAX_PER_KIND + 200),
        );
        $items = [...$late, new WatchItem('stock.lot_expired', 'lot-1', []), new WatchItem('stock.lot_expired', 'lot-2', [])];

        $resource = WatchResource::of($items);

        self::assertSame(WatchResource::MAX_PER_KIND + 202, $resource->count, 'the count is every condition, not the page');
        $kinds = array_count_values(array_column($resource->items, 'kind'));
        self::assertSame(WatchResource::MAX_PER_KIND, $kinds['invoices.late_customer']);
        self::assertSame(2, $kinds['stock.lot_expired'], 'a kind far down the catalogue is not starved by an earlier, larger one');
        self::assertSame('customer-1', $resource->items[0]['subjectId'], 'each kind keeps its first conditions, in the catalogue\'s order');
    }

    public function testASmallListIsSentWhole(): void
    {
        $resource = WatchResource::of([new WatchItem('stock.lot_expired', null, [])]);

        self::assertSame(1, $resource->count);
        self::assertCount(1, $resource->items);
    }
}
