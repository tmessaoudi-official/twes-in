<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Realtime;

use App\Shared\Application\RealtimeTokens;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A realtime token lives two minutes, from `app.realtime.token_lifetime`: a connection whose session ended is closed at
 * once, and one that escaped that close cannot renew past its token without a session that still stands.
 */
final class RealtimeTokenLifetimeTest extends KernelTestCase
{
    public function testATokenLivesAsLongAsTheParameterSaysTwoMinutes(): void
    {
        $lifetime = static::getContainer()->getParameter('app.realtime.token_lifetime');
        self::assertSame(120, $lifetime);
        $clock = static::getContainer()->get(ClockInterface::class);

        $before = $clock->now()->getTimestamp();
        $issued = static::getContainer()->get(RealtimeTokens::class)->issue(Uuid::v7(), null);
        $after = $clock->now()->getTimestamp();

        self::assertGreaterThanOrEqual($before + $lifetime, $issued->expiresAt->getTimestamp());
        self::assertLessThanOrEqual($after + $lifetime, $issued->expiresAt->getTimestamp());
    }
}
