<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Bridge\Monolog\Processor\DebugProcessor;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The log records a request keeps for the profiler, development only, up to a number, errors always: Symfony's
 * processor keeps every one with its context, and Doctrine logs each query with its parameters, so an import of 4,000
 * rows held about 110 MB of them and its preview ran out of memory. Every record still reaches the log files.
 */
#[AsDecorator('debug.log_processor', onInvalid: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]
final class BoundedDebugLogs extends DebugProcessor
{
    private int $kept = 0;

    public function __construct(
        private readonly DebugProcessor $inner,
        #[Autowire(param: 'app.debug.logs_kept')]
        private readonly int $limit,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if ($record->level->isHigherThan(Level::Warning)) {
            return ($this->inner)($record);
        }
        if ($this->kept >= $this->limit) {
            return $record;
        }
        ++$this->kept;

        return ($this->inner)($record);
    }

    public function getLogs(?Request $request = null): array
    {
        return $this->inner->getLogs($request);
    }

    public function countErrors(?Request $request = null): int
    {
        return $this->inner->countErrors($request);
    }

    public function clear(): void
    {
        $this->kept = 0;
        $this->inner->clear();
    }

    public function reset(): void
    {
        $this->clear();
    }
}
