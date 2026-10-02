<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\Session\ManageSessions;
use App\Identity\Application\Session\SessionStanding;
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemorySessions;
use App\Tests\Support\InMemoryUsers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ManageSessionsTest extends TestCase
{
    private InMemorySessions $sessions;
    private InMemoryUsers $users;
    private FakeTransactions $transactions;
    private InMemoryAuditTrail $audit;
    private MockClock $clock;
    private User $user;

    protected function setUp(): void
    {
        $this->sessions = new InMemorySessions();
        $this->transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($this->transactions);
        $this->clock = new MockClock('2026-10-02 12:00:00');
        $this->user = new User(Email::fromString('someone@example.test'), 'Someone');
        $this->users = new InMemoryUsers();
        $this->users->save($this->user);
    }

    private function manage(): ManageSessions
    {
        return new ManageSessions($this->sessions, $this->users, $this->audit, $this->transactions, $this->clock, 28800);
    }

    public function testTheFirstRequestOfASessionRecordsItWithItsDevice(): void
    {
        $standing = $this->manage()->seen($this->user->getId(), 'session-a', $this->sessions->ofSessionId('session-a'), 'Firefox on Linux', '203.0.113.7');

        self::assertSame(SessionStanding::Active, $standing);
        self::assertCount(1, $this->sessions->all);
        self::assertSame('Firefox on Linux', $this->sessions->all[0]->getDevice());
        self::assertSame('203.0.113.7', $this->sessions->all[0]->getAddress());
    }

    public function testASessionIsNotWrittenAgainWithinFiveMinutesButIsAfter(): void
    {
        $this->manage()->seen($this->user->getId(), 'session-a', $this->sessions->ofSessionId('session-a'), 'Firefox', '203.0.113.7');
        $first = $this->sessions->all[0]->getLastSeenAt();

        $this->clock->modify('+4 minutes');
        $this->manage()->seen($this->user->getId(), 'session-a', $this->sessions->ofSessionId('session-a'), 'Firefox', '203.0.113.7');
        self::assertEquals($first, $this->sessions->all[0]->getLastSeenAt(), 'a request a minute later writes nothing');

        $this->clock->modify('+2 minutes');
        $this->manage()->seen($this->user->getId(), 'session-a', $this->sessions->ofSessionId('session-a'), 'Firefox', '203.0.113.7');
        self::assertGreaterThan($first, $this->sessions->all[0]->getLastSeenAt());
        self::assertCount(1, $this->sessions->all);
    }

    public function testAnEndedSessionIsReportedRevokedAndStaysSo(): void
    {
        $this->manage()->seen($this->user->getId(), 'session-a', $this->sessions->ofSessionId('session-a'), 'Firefox', '203.0.113.7');
        $id = $this->sessions->all[0]->getId();

        $this->manage()->end($this->user, $id, 'session-b');

        self::assertSame(SessionStanding::Revoked, $this->manage()->seen($this->user->getId(), 'session-a', $this->sessions->ofSessionId('session-a'), 'Firefox', '203.0.113.7'));
        self::assertTrue($this->manage()->recorded('session-a')?->isRevoked());
        self::assertNull($this->manage()->recorded('never-seen'));
        self::assertSame(['auth.session_ended'], array_map(static fn ($entry): string => $entry->action, $this->audit->entries));
    }

    public function testAPersonCannotEndTheSessionTheyAreUsingOrSomeoneElses(): void
    {
        $this->manage()->seen($this->user->getId(), 'session-a', $this->sessions->ofSessionId('session-a'), 'Firefox', '203.0.113.7');
        $id = $this->sessions->all[0]->getId();

        self::assertFalse($this->manage()->end($this->user, $id, 'session-a'), 'ending this one is signing out');

        $other = new User(Email::fromString('other@example.test'), 'Other');
        self::assertFalse($this->manage()->end($other, $id, 'session-z'), 'another account\'s session is not found');
        self::assertSame(SessionStanding::Active, $this->manage()->seen($this->user->getId(), 'session-a', $this->sessions->ofSessionId('session-a'), 'Firefox', '203.0.113.7'));
    }

    public function testEndingTheOthersKeepsTheCurrentOne(): void
    {
        foreach (['session-a', 'session-b', 'session-c'] as $id) {
            $this->manage()->seen($this->user->getId(), $id, $this->sessions->ofSessionId($id), 'Device '.$id, '203.0.113.7');
        }

        $ended = $this->manage()->endOthers($this->user, 'session-b');

        self::assertSame(2, $ended);
        self::assertSame(SessionStanding::Active, $this->manage()->seen($this->user->getId(), 'session-b', $this->sessions->ofSessionId('session-b'), 'x', 'y'));
        self::assertSame(SessionStanding::Revoked, $this->manage()->seen($this->user->getId(), 'session-a', $this->sessions->ofSessionId('session-a'), 'x', 'y'));
        self::assertSame(['auth.sessions_ended'], array_values(array_unique(array_map(static fn ($entry): string => $entry->action, $this->audit->entries))));
    }

    public function testTheListShowsLiveSessionsOnlyAndMarksTheCurrentOne(): void
    {
        $this->manage()->seen($this->user->getId(), 'session-a', $this->sessions->ofSessionId('session-a'), 'Old', '203.0.113.7');
        $this->clock->modify('+9 hours'); // past the absolute limit of eight
        $this->manage()->seen($this->user->getId(), 'session-b', $this->sessions->ofSessionId('session-b'), 'Now', '203.0.113.8');

        $list = $this->manage()->listFor($this->user, 'session-b');

        self::assertCount(1, $list);
        self::assertSame('Now', $list[0]->session->getDevice());
        self::assertTrue($list[0]->current);
    }
}
