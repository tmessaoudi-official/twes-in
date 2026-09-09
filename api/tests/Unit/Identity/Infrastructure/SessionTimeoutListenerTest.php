<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure;

use App\Identity\Infrastructure\Session\SessionTimeoutListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SessionTimeoutListenerTest extends TestCase
{
    private const int IDLE = 1800;
    private const int ABSOLUTE = 43200;
    private const int NOW = 1_800_000_000;

    public function testAFreshSessionIsKept(): void
    {
        $session = $this->drive(created: self::NOW - 60, lastUsed: self::NOW - 10);

        self::assertSame('kept', $session->get('marker'));
    }

    public function testASessionIdleLongerThanTheIdleLimitIsInvalidated(): void
    {
        $session = $this->drive(created: self::NOW - 3600, lastUsed: self::NOW - self::IDLE - 1);

        self::assertNull($session->get('marker'));
    }

    public function testASessionUsedRecentlyButOlderThanTheAbsoluteLimitIsInvalidated(): void
    {
        $session = $this->drive(created: self::NOW - self::ABSOLUTE - 1, lastUsed: self::NOW - 5);

        self::assertNull($session->get('marker'));
    }

    public function testExactlyAtTheLimitsIsStillInside(): void
    {
        $session = $this->drive(created: self::NOW - self::ABSOLUTE, lastUsed: self::NOW - self::IDLE);

        self::assertSame('kept', $session->get('marker'));
    }

    public function testARequestWithoutASessionCookieStartsNothing(): void
    {
        $session = $this->drive(created: self::NOW - self::ABSOLUTE - 1, lastUsed: self::NOW - 5, withCookie: false);

        self::assertFalse($session->isStarted());
    }

    public function testASubRequestIsIgnored(): void
    {
        $session = $this->drive(created: self::NOW - self::ABSOLUTE - 1, lastUsed: self::NOW - 5, requestType: HttpKernelInterface::SUB_REQUEST);

        self::assertFalse($session->isStarted());
    }

    private function drive(int $created, int $lastUsed, bool $withCookie = true, int $requestType = HttpKernelInterface::MAIN_REQUEST): Session
    {
        $storage = new MockArraySessionStorage();
        $storage->setSessionData([
            '_sf2_meta' => [MetadataBag::CREATED => $created, MetadataBag::UPDATED => $lastUsed, MetadataBag::LIFETIME => 0],
            '_sf2_attributes' => ['marker' => 'kept'],
        ]);
        $session = new Session($storage);
        $request = Request::create('/api/auth/me');
        $request->setSession($session);
        if ($withCookie) {
            $request->cookies->set($session->getName(), 'previous');
        }

        $listener = new SessionTimeoutListener(new MockClock('@'.self::NOW), self::IDLE, self::ABSOLUTE);
        $listener(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $requestType));

        return $session;
    }
}
