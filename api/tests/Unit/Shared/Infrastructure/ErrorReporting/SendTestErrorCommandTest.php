<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\ErrorReporting;

use App\Shared\Infrastructure\ErrorReporting\Console\SendTestErrorCommand;
use App\Shared\Infrastructure\ErrorReporting\TestError;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SendTestErrorCommandTest extends TestCase
{
    /**
     * The error carries one of each value the scrubber masks, so reading it back in the tracker shows the masking held
     * (scripts/monitor/monitor-check.sh).
     */
    public function testItFailsWithAnErrorCarryingEveryKindOfValueTheScrubberMasks(): void
    {
        try {
            new CommandTester(new SendTestErrorCommand())->execute([]);
            self::fail('The command was meant to fail.');
        } catch (TestError $error) {
            self::assertStringContainsString('@', $error->getMessage());
            self::assertMatchesRegularExpression('/"[^"]+"/', $error->getMessage());
            self::assertMatchesRegularExpression('/\d{6,}/', $error->getMessage());
            self::assertStringStartsWith('Test error from app:errors:test', $error->getMessage());
        }
    }
}
