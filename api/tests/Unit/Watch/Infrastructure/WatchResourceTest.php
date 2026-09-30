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
    public function testACompanyWithManyConditionsSendsAPageOfThemAndTheTrueCount(): void
    {
        $items = array_map(
            static fn (int $n): WatchItem => new WatchItem('invoices.late_customer', \sprintf('customer-%d', $n), ['invoices' => 1]),
            range(1, WatchResource::MAX_ITEMS + 50),
        );

        $resource = WatchResource::of($items);

        self::assertSame(WatchResource::MAX_ITEMS + 50, $resource->count, 'the count is every condition, not the page');
        self::assertCount(WatchResource::MAX_ITEMS, $resource->items);
        self::assertSame('customer-1', $resource->items[0]['subjectId'], 'the page is the first conditions, in the catalogue\'s order');
    }

    public function testASmallListIsSentWhole(): void
    {
        $resource = WatchResource::of([new WatchItem('stock.lot_expired', null, [])]);

        self::assertSame(1, $resource->count);
        self::assertCount(1, $resource->items);
    }
}
