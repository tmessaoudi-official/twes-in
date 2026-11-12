<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\Depends;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every functional case runs on a clock held still at a whole second, the one the API reads: a day a test reads and the
 * request sending it are one instant, so midnight in Tunis (23:00 UTC) never falls between them, and a row a test stamps
 * with apiNow() is never a fraction of a second before the API's stamp of the same second. What a test computes beside
 * the API (an authenticator's code, a moment it writes) reads that clock too.
 */
final class ClockHeldStillTest extends ApiTestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    public function testTheClockStandsStillOnAWholeSecond(): void
    {
        $read = Clock::get()->now();
        usleep(2000);

        self::assertInstanceOf(MockClock::class, Clock::get(), 'the clock is held for the case');
        self::assertEquals($read, Clock::get()->now(), 'a day read and the request sending it are one instant');
        self::assertSame('000000', $read->format('u'), 'held on a whole second, as apiNow() writes it');
    }

    public function testACaseMovingTheClockMovesItForItselfAlone(): void
    {
        Clock::set(new MockClock(new \DateTimeImmutable('2000-01-01 12:00:00', new \DateTimeZone('UTC'))));

        self::assertSame('2000-01-01', Clock::get()->now()->format('Y-m-d'));
    }

    #[Depends('testACaseMovingTheClockMovesItForItselfAlone')]
    public function testTheNextCaseHoldsTheWallClockAgain(): void
    {
        $held = Clock::get()->now()->getTimestamp();
        $wall = new \DateTimeImmutable()->getTimestamp(); // test-clock: the wall's own now, which the held clock is read against

        self::assertLessThan(60, abs($wall - $held), 'the last case\'s move is gone, the held clock is now\'s');
    }

    public function testARowStampedBesideTheApiReadsTheHeldClock(): void
    {
        Clock::set(new MockClock(new \DateTimeImmutable('2000-01-01 12:00:00', new \DateTimeZone('UTC'))));

        self::assertSame('2000-01-01 12:00:00', self::apiNow());
    }

    public function testARowStampedBeforeOrAfterTheApiCountsFromTheHeldClock(): void
    {
        Clock::set(new MockClock(new \DateTimeImmutable('2000-01-01 00:30:00', new \DateTimeZone('Africa/Tunis'))));

        self::assertSame(['1999-12-31 22:30:00', '1999-12-31 23:31:00'], [self::apiNow('-1 hour'), self::apiNow('+1 minute')], 'shifted from the held instant, written in UTC');
    }

    public function testSigningInWithAnAuthenticatorReadsTheClockTheApiReads(): void
    {
        $this->createUser('owner@twes.local', self::PASSWORD, $this->createCompany(), authenticator: true);
        Clock::set(new MockClock(Clock::get()->now()->modify('+10 minutes')));

        $this->login('owner@twes.local', self::PASSWORD);

        self::assertResponseStatusCodeSame(Response::HTTP_OK, 'the code is the one for the API\'s now, ten minutes on');
    }
}
