<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Logging;

use App\Shared\Infrastructure\Logging\BoundedDebugLogs;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Monolog\Processor\DebugProcessor;

final class BoundedDebugLogsTest extends TestCase
{
    public function testARequestKeepsItsFirstRecordsAndNoMore(): void
    {
        $logs = new BoundedDebugLogs(new DebugProcessor(), 3);

        foreach (['a', 'b', 'c', 'd', 'e'] as $message) {
            $logs(self::record($message, Level::Debug));
        }

        self::assertSame(['a', 'b', 'c'], array_column($logs->getLogs(), 'message'));
    }

    public function testAnErrorIsAlwaysKeptAndCounted(): void
    {
        $logs = new BoundedDebugLogs(new DebugProcessor(), 1);

        $logs(self::record('a', Level::Debug));
        $logs(self::record('b', Level::Info));
        $logs(self::record('c', Level::Error));

        self::assertSame(['a', 'c'], array_column($logs->getLogs(), 'message'));
        self::assertSame(1, $logs->countErrors());
    }

    public function testTheCountStartsAgainWithTheNextRequest(): void
    {
        $logs = new BoundedDebugLogs(new DebugProcessor(), 1);
        $logs(self::record('a', Level::Debug));
        $logs(self::record('b', Level::Debug));

        $logs->reset();
        $logs(self::record('c', Level::Debug));

        self::assertSame(['c'], array_column($logs->getLogs(), 'message'));
    }

    public function testARecordIsHandedOnUnchanged(): void
    {
        $logs = new BoundedDebugLogs(new DebugProcessor(), 0);
        $record = self::record('a', Level::Debug);

        self::assertSame($record, $logs($record));
    }

    private static function record(string $message, Level $level): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'doctrine', $level, $message);
    }
}
