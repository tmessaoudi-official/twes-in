<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Infrastructure\Realtime\CentrifugoPublisher;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Logs by channel (docs/SPEC.md § 7, 2026-09-17): what the application writes is filed under a channel of its own.
 */
final class LogChannelsTest extends KernelTestCase
{
    public function testTheRealtimePublisherLogsInTheRealtimeChannel(): void
    {
        $publisher = static::getContainer()->get(CentrifugoPublisher::class);

        $logger = (new \ReflectionProperty(CentrifugoPublisher::class, 'logger'))->getValue($publisher);

        self::assertInstanceOf(Logger::class, $logger);
        self::assertSame('realtime', $logger->getName());
    }
}
