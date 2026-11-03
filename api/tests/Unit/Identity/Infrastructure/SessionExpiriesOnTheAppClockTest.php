<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure;

use App\Identity\Infrastructure\Passkey\PasskeyChallenges;
use App\Identity\Infrastructure\Security\PendingSecondFactor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;

/**
 * The half sign-in and a passkey ceremony expire on the application's clock, never the server's: a clock moved past
 * the wait ends them, and one held just before it keeps them.
 */
final class SessionExpiriesOnTheAppClockTest extends TestCase
{
    private RequestStack $requests;

    protected function setUp(): void
    {
        $this->requests = new RequestStack();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requests->push($request);
    }

    public function testTheHalfSignInWaitsUntilTheApplicationClockPassesItsTime(): void
    {
        $clock = new MockClock('2026-11-03 09:00:00');
        $pending = new PendingSecondFactor($this->requests, 'PT5M', $clock);
        $user = Uuid::v7();

        $pending->open($user);
        $clock->sleep(300);
        self::assertEquals($user, $pending->waiting(), 'five minutes on the application clock, still within the wait');

        $clock->sleep(1);
        self::assertNull($pending->waiting(), 'a second past it, the half sign-in has ended');
    }

    public function testAPasskeyCeremonyEndsWhenTheApplicationClockPassesItsTime(): void
    {
        $clock = new MockClock('2026-11-03 09:00:00');
        $challenges = new PasskeyChallenges($this->requests, 'PT5M', $clock);
        $user = Uuid::v7();

        $challenges->remember('login', $user, '{"challenge":"a"}');
        $clock->sleep(300);
        self::assertSame('{"challenge":"a"}', $challenges->take('login', $user));

        $challenges->remember('login', $user, '{"challenge":"b"}');
        $clock->sleep(301);
        self::assertNull($challenges->take('login', $user), 'past its wait on the application clock, the ceremony is gone');
    }
}
