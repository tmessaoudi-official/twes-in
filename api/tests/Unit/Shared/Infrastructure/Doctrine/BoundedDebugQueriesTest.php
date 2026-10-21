<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\BoundedDebugQueries;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\Query;

final class BoundedDebugQueriesTest extends TestCase
{
    public function testARequestKeepsItsFirstQueriesAndNoMore(): void
    {
        $queries = new BoundedDebugQueries(new DebugDataHolder(), 3);

        foreach (['a', 'b', 'c', 'd', 'e'] as $sql) {
            $queries->addQuery('default', new Query("SELECT '$sql'"));
        }

        self::assertSame(["SELECT 'a'", "SELECT 'b'", "SELECT 'c'"], array_column($queries->getData()['default'], 'sql'));
    }

    public function testTheCountStartsAgainWithTheNextRequest(): void
    {
        $queries = new BoundedDebugQueries(new DebugDataHolder(), 2);
        foreach (['a', 'b', 'c'] as $sql) {
            $queries->addQuery('default', new Query("SELECT '$sql'"));
        }

        $queries->reset();
        $queries->addQuery('owner', new Query("SELECT 'd'"));

        self::assertSame(['owner' => ["SELECT 'd'"]], array_map(static fn (array $kept): array => array_column($kept, 'sql'), $queries->getData()));
    }
}
