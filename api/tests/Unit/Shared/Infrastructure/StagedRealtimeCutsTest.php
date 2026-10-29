<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Application\RealtimeSessions;
use App\Shared\Infrastructure\Realtime\StagedRealtimeCuts;
use App\Tests\Support\RecordingRealtimePublisher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A person's connections are closed once every session of theirs ended and that end is stored: never for a unit of
 * work rolled back, whose sessions still stand, and once per person however many times it was asked.
 */
#[CoversClass(StagedRealtimeCuts::class)]
final class StagedRealtimeCutsTest extends TestCase
{
    private RecordingRealtimePublisher $publisher;
    private StagedRealtimeCuts $cuts;

    protected function setUp(): void
    {
        $this->publisher = new RecordingRealtimePublisher();
        $this->cuts = new StagedRealtimeCuts($this->publisher, self::sessions(Uuid::v7(), []));
    }

    /** @param list<Uuid> $held the sessions that account holds, and no other account any */
    private static function sessions(Uuid $user, array $held): RealtimeSessions
    {
        return new class($user, $held) implements RealtimeSessions {
            /** @param list<Uuid> $held */
            public function __construct(private Uuid $user, private array $held)
            {
            }

            public function of(Uuid $userId): array
            {
                return $userId->equals($this->user) ? $this->held : [];
            }
        };
    }

    public function testACutWaitsForTheOutermostCommitThenClosesEachPersonOnce(): void
    {
        $nadia = Uuid::v7();
        $this->cuts->begin();
        $this->cuts->begin();
        $this->cuts->cut($nadia);
        $this->cuts->cut($nadia);
        $this->cuts->commit();
        self::assertSame([], $this->publisher->disconnected, 'nothing before the outermost commit');

        $this->cuts->commit();

        self::assertSame([$nadia->toRfc4122()], $this->publisher->disconnected);
    }

    /** An account is closed whole, each session's subject and its own; a session alone is closed alone, and nobody else's. */
    public function testAnAccountIsClosedSessionBySessionAndASessionAlone(): void
    {
        $nadia = Uuid::v7();
        $laptop = Uuid::v7();
        $phone = Uuid::v7();
        $sami = Uuid::v7();
        $kept = Uuid::v7();
        $this->cuts = new StagedRealtimeCuts($this->publisher, self::sessions($nadia, [$laptop, $phone]));

        $this->cuts->begin();
        $this->cuts->cut($nadia);
        $this->cuts->cutSession($sami, $kept);
        $this->cuts->cutSession($nadia, $phone);
        $this->cuts->commit();

        $n = $nadia->toRfc4122();
        self::assertSame([$n, $n.'.'.$laptop->toRfc4122(), $n.'.'.$phone->toRfc4122(), $sami->toRfc4122().'.'.$kept->toRfc4122()], $this->publisher->disconnected);
    }

    public function testARolledBackUnitClosesNothingAndACutOutsideAUnitIsDropped(): void
    {
        $this->cuts->cut(Uuid::v7());
        $this->cuts->begin();
        $this->cuts->cut(Uuid::v7());
        $this->cuts->rollBack();
        $this->cuts->begin();
        $this->cuts->commit();

        self::assertSame([], $this->publisher->disconnected);
    }

    public function testResetBetweenRequestsForgetsWhatAnAbandonedUnitStaged(): void
    {
        $this->cuts->begin();
        $this->cuts->cut(Uuid::v7());
        $this->cuts->reset();
        $this->cuts->begin();
        $this->cuts->commit();

        self::assertSame([], $this->publisher->disconnected);
    }
}
