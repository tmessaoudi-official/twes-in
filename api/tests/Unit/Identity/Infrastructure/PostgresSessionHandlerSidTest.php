<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure;

use App\Identity\Infrastructure\Session\PostgresSessionHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PHP 8.6 warns, as the class is declared, about a session handler without create_sid(), which PHP 9 will require. The
 * handler names a new session the way PHP does, without opening anything: no database is reached here.
 */
final class PostgresSessionHandlerSidTest extends TestCase
{
    public function testTheHandlerDeclaresHowANewSessionIsNamed(): void
    {
        self::assertSame(
            PostgresSessionHandler::class,
            new \ReflectionMethod(PostgresSessionHandler::class, 'create_sid')->getDeclaringClass()->getName(),
        );
    }

    public function testANewSessionIdIsOnePhpAcceptsAndNeverTheSameTwice(): void
    {
        $handler = new PostgresSessionHandler('postgresql://nobody@localhost:1/none', new RequestStack(), 1800, new MockClock());

        $first = $handler->create_sid();
        $second = $handler->create_sid();

        // PHP's own alphabet for every sid_bits_per_character, at a length its session ids may have.
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9,-]{22,256}$/', $first);
        self::assertNotSame($first, $second);
    }
}
