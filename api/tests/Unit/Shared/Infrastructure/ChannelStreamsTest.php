<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Infrastructure\Logging\ChannelStreams;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Where production logs go (docs/SPEC.md § 7, 2026-09-17): one JSON line per record on one stream in a container,
 * whose log driver rotates it, and one JSON file per channel when self-hosted without containers.
 */
#[CoversClass(ChannelStreams::class)]
final class ChannelStreamsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/twes-channel-streams-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testWithoutADirectoryEveryChannelGoesToTheOneStreamAsJsonNamingItsChannel(): void
    {
        $stream = $this->directory.'/stderr';
        $handler = new ChannelStreams('', $stream);

        $handler->handle($this->record('request', 'Uncaught exception'));
        $handler->handle($this->record('doctrine', 'SELECT 1'));
        $handler->close();

        $lines = $this->lines($stream);
        self::assertCount(2, $lines);
        self::assertSame(['request', 'Uncaught exception'], [$lines[0]['channel'], $lines[0]['message']]);
        self::assertSame(['doctrine', 'SELECT 1'], [$lines[1]['channel'], $lines[1]['message']]);
        self::assertSame(['stderr'], array_map('basename', glob($this->directory.'/*') ?: []));
    }

    public function testWithADirectoryEachChannelHasItsOwnFile(): void
    {
        $handler = new ChannelStreams($this->directory, $this->directory.'/unused');

        $handler->handle($this->record('request', 'Matched route'));
        $handler->handle($this->record('security', 'Authenticated'));
        $handler->handle($this->record('request', 'Uncaught exception'));
        $handler->close();

        self::assertSame(['request.log', 'security.log'], array_map('basename', glob($this->directory.'/*') ?: []));
        self::assertSame(['Matched route', 'Uncaught exception'], array_column($this->lines($this->directory.'/request.log'), 'message'));
        self::assertSame(['Authenticated'], array_column($this->lines($this->directory.'/security.log'), 'message'));
    }

    public function testAChannelNameNeverLeavesTheDirectory(): void
    {
        $handler = new ChannelStreams($this->directory, $this->directory.'/unused');

        $handler->handle($this->record('../escape', 'Nope'));
        $handler->close();

        self::assertSame(['escape.log'], array_map('basename', glob($this->directory.'/*') ?: []));
    }

    private function record(string $channel, string $message): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), $channel, Level::Error, $message);
    }

    /** @return list<array<mixed>> */
    private function lines(string $file): array
    {
        $lines = [];
        foreach (file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $lines[] = $decoded;
        }

        return $lines;
    }
}
