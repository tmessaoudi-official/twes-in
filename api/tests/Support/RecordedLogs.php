<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

/**
 * What a channel logged, kept for a functional test to read after its request. Monolog's TestHandler would not do:
 * it is reset with the other services once a request is over, so its records are gone before the test asks.
 */
final class RecordedLogs extends AbstractProcessingHandler
{
    /** @var list<LogRecord> */
    public static array $records = [];

    protected function write(LogRecord $record): void
    {
        self::$records[] = $record;
    }
}
