<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency;

use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tests\Concurrency\Support\Outcome;
use App\Tests\Concurrency\Support\Race;
use App\Tests\Concurrency\Support\Steps\IncrementCounter;
use App\Tests\Concurrency\Support\Steps\LockInOppositeOrders;
use App\Tests\Concurrency\Support\StressDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * The harness proves itself before any race test relies on it: it must find a race that is there (a lost update
 * without a lock, every round, which only racers truly released together produce), find none where a lock serializes
 * them, and report a deadlock as a failure of its own kind. A harness that only ever answers « fine » proves nothing.
 */
final class HarnessTest extends TestCase
{
    private const int ROUNDS = 5;
    /** Six times the step's 50 ms window: racers this far apart overlap only because the barrier lines them up. */
    private const int STAGGER_MS = 300;

    private Connection $owner;

    protected function setUp(): void
    {
        $this->owner = StressDatabase::connect();
        $this->owner->executeStatement('CREATE TABLE IF NOT EXISTS stress_counter (id INT PRIMARY KEY, n INT NOT NULL)');
        $this->owner->executeStatement('GRANT SELECT, UPDATE ON stress_counter TO twes_app');
    }

    protected function tearDown(): void
    {
        $this->owner->executeStatement('DROP TABLE IF EXISTS stress_counter');
        $this->owner->close();
    }

    public function testTwoWritersWithoutALockLoseAnUpdateEveryRound(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $this->reset();
            $outcomes = Race::run(IncrementCounter::class, 2, ['locked' => false], staggerMs: self::STAGGER_MS);

            $this->assertAllOk($outcomes);
            self::assertSame([0, 0], array_map(static fn (Outcome $outcome): mixed => $outcome->value, $outcomes), "round $round: both racers read before either wrote");
            self::assertSame(1, $this->counter(), "round $round: one increment lost");
        }
    }

    public function testTheSameWritersUnderALockLoseNothing(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $this->reset();
            $outcomes = Race::run(IncrementCounter::class, 2, ['locked' => true], staggerMs: self::STAGGER_MS);

            $this->assertAllOk($outcomes);
            self::assertSame(2, $this->counter(), "round $round: no increment lost");
        }
    }

    public function testADeadlockIsAFailureOfItsOwnKind(): void
    {
        $this->reset();
        $kinds = array_map(static fn (Outcome $outcome): string => $outcome->kind, Race::run(LockInOppositeOrders::class, 2));
        sort($kinds);

        self::assertSame([Outcome::DEADLOCK, Outcome::OK], $kinds);
    }

    public function testAnyOtherDatabaseIsRefused(): void
    {
        $token = $_SERVER['TEST_TOKEN'] ?? null;
        $_SERVER['TEST_TOKEN'] = '';
        try {
            $this->expectExceptionMessage('never on twes_test');
            StressDatabase::name();
        } finally {
            $_SERVER['TEST_TOKEN'] = $token;
        }
    }

    private function reset(): void
    {
        $this->owner->executeStatement('DELETE FROM stress_counter');
        $this->owner->executeStatement('INSERT INTO stress_counter (id, n) VALUES (1, 0), (2, 0)');
    }

    private function counter(): int
    {
        return Rows::int($this->owner->fetchOne('SELECT n FROM stress_counter WHERE id = 1'));
    }

    /** @param list<Outcome> $outcomes */
    private function assertAllOk(array $outcomes): void
    {
        foreach ($outcomes as $outcome) {
            self::assertSame(Outcome::OK, $outcome->kind, $outcome->describe());
        }
    }
}
