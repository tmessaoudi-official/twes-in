<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Infrastructure\Scheduler\RemoveExpiredSessionsEveryHour;
use App\Identity\Infrastructure\Session\ExpiredSessions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A session nobody comes back to is read as none once its expiry has passed, but its row stays, with what it held,
 * until something takes it away: PHP's own collection runs on a share of the session starts, so a quiet installation
 * keeps them for weeks. The worker takes every expired row away each hour, by the application's clock.
 */
final class ExpiredSessionsTest extends KernelTestCase
{
    private const int NOW = 1_800_000_000;

    public function testEveryRowPastItsExpiryGoesAndNoOtherOne(): void
    {
        $this->row('expired-long-ago', self::NOW - 86_400);
        $this->row('expired-a-second-ago', self::NOW - 1);
        $this->row('expiring-now', self::NOW);
        $this->row('still-open', self::NOW + 1_800);

        new RemoveExpiredSessionsEveryHour($this->expired(), new MockClock(new \DateTimeImmutable('@'.self::NOW)), new NullLogger())();

        self::assertSame(['expiring-now', 'still-open'], $this->left());
    }

    public function testItSaysHowManyWent(): void
    {
        $this->row('expired-one', self::NOW - 10);
        $this->row('expired-two', self::NOW - 20);
        $this->row('still-open', self::NOW + 10);

        self::assertSame(2, $this->expired()->removeBefore(self::NOW));
        self::assertSame(['still-open'], $this->left());
    }

    private function row(string $id, int $expiry): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO sessions (sess_id, sess_data, sess_lifetime, sess_time) VALUES (?, ?, ?, ?)',
            ['expired-sessions-test-'.$id, 'company|s:1:"A";', $expiry, $expiry - 1_800],
            [ParameterType::STRING, ParameterType::BINARY, ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }

    /** @return list<string> */
    private function left(): array
    {
        $ids = $this->connection()->fetchFirstColumn("SELECT sess_id FROM sessions WHERE sess_id LIKE 'expired-sessions-test-%' ORDER BY sess_id");

        return array_map(static fn (mixed $id): string => substr(\is_string($id) ? $id : '', \strlen('expired-sessions-test-')), $ids);
    }

    private function expired(): ExpiredSessions
    {
        return self::getContainer()->get(ExpiredSessions::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
