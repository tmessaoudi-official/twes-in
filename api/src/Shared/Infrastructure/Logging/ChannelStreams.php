<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Where production logs are written (docs/SPEC.md § 7, 2026-09-17), behind the one `fingers_crossed` handler, so a
 * request that fails still brings every channel's context along. In a container, `LOG_DIRECTORY` is empty and every
 * record is one JSON line on STDERR, naming its channel, for the log driver to rotate. Self-hosted without
 * containers, it names a directory and each channel has its own JSON file there (`request.log`, `doctrine.log`…),
 * rotated by the shipped logrotate configuration.
 */
final class ChannelStreams extends AbstractProcessingHandler
{
    /** @var array<string, StreamHandler> */
    private array $streams = [];

    public function __construct(
        private readonly string $directory,
        private readonly string $stream = 'php://stderr',
        int|string|Level $level = Level::Debug,
    ) {
        parent::__construct($level);
    }

    protected function write(LogRecord $record): void
    {
        $name = '' === $this->directory ? '' : $this->fileName($record->channel);
        $this->streams[$name] ??= $this->open('' === $name ? $this->stream : rtrim($this->directory, '/').'/'.$name);
        $this->streams[$name]->handle($record);
    }

    public function close(): void
    {
        foreach ($this->streams as $stream) {
            $stream->close();
        }
        $this->streams = [];
        parent::close();
    }

    public function reset(): void
    {
        foreach ($this->streams as $stream) {
            $stream->reset();
        }
        parent::reset();
    }

    private function fileName(string $channel): string
    {
        $safe = preg_replace('/[^a-z0-9_-]+/', '', strtolower($channel));

        return ('' === $safe || null === $safe ? 'app' : $safe).'.log';
    }

    private function open(string $path): StreamHandler
    {
        $handler = new StreamHandler($path, $this->level, true, null, false);
        $handler->setFormatter(new JsonFormatter());

        return $handler;
    }
}
