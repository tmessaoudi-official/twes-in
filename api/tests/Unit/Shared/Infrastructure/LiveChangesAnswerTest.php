<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Infrastructure\Realtime\LiveChangesAnswer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The answer to a tab's request names what the request changed, so every screen of that tab hears its own change as
 * the other tabs hear it. A proxy refuses an answer whose headers outgrow its buffer (4 KB by default for nginx), so
 * the list is folded to one bulk change per kind and action before it grows past 2 KB.
 */
#[CoversClass(LiveChangesAnswer::class)]
final class LiveChangesAnswerTest extends TestCase
{
    public function testWhatTheRequestSaidIsWrittenAsItWasSaid(): void
    {
        $said = [
            ['kind' => 'product', 'id' => '0199a0c2-0000-7000-8000-000000000001', 'action' => 'product.revised'],
            ['kind' => 'customer', 'id' => null, 'action' => 'customer.created', 'bulk' => true, 'count' => 11],
        ];

        self::assertSame($said, json_decode(self::header($said), true));
    }

    public function testNothingSaidWritesNoHeader(): void
    {
        self::assertNull(LiveChangesAnswer::header([]));
    }

    public function testAListTooLongForAProxyIsFoldedToOneBulkChangePerKindAndAction(): void
    {
        $said = [];
        for ($i = 0; $i < 30; ++$i) {
            $said[] = ['kind' => 'product', 'id' => \sprintf('0199a0c2-0000-7000-8000-%012d', $i), 'action' => 'product.deleted'];
        }
        $said[] = ['kind' => 'product', 'id' => null, 'action' => 'product.deleted', 'bulk' => true, 'count' => 12];
        $said[] = ['kind' => 'stock', 'id' => '0199a0c2-0000-7000-8000-999999999999', 'action' => 'stock.moved'];

        $header = self::header($said);

        self::assertLessThanOrEqual(2048, \strlen($header));
        self::assertSame([
            ['kind' => 'product', 'id' => null, 'action' => 'product.deleted', 'bulk' => true, 'count' => 42],
            ['kind' => 'stock', 'id' => null, 'action' => 'stock.moved', 'bulk' => true, 'count' => 1],
        ], json_decode($header, true));
    }

    public function testEvenFoldedTheListNeverOutgrowsTheLimit(): void
    {
        $said = [];
        for ($i = 0; $i < 200; ++$i) {
            $said[] = ['kind' => 'kind_'.$i, 'id' => null, 'action' => 'kind_'.$i.'.created_with_a_long_name'];
        }

        $header = self::header($said);

        self::assertLessThanOrEqual(2048, \strlen($header));
        self::assertIsList(json_decode($header, true));
        self::assertNotEmpty(json_decode($header, true), 'what fits is still said');
    }

    /** @param list<array{kind: string, id: ?string, action: string, bulk?: true, count?: int}> $said */
    private static function header(array $said): string
    {
        $header = LiveChangesAnswer::header($said);
        self::assertNotNull($header);

        return $header;
    }
}
