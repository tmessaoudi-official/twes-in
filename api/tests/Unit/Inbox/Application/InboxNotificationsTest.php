<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Inbox\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Inbox\Application\InboxNotifications;
use App\Shared\Application\Notification;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Role;
use App\Tests\Support\InMemoryCompanies;
use App\Tests\Support\InMemoryInbox;
use App\Tests\Support\InMemoryMemberships;
use App\Tests\Support\InMemoryUsers;
use App\Tests\Support\RecordingRealtimePublisher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Publishing keeps one row per recipient and then pushes (docs/SPEC.md § 7, 2026-09-13): the row is the truth,
 * the push is how an open page hears about it without asking.
 */
#[CoversClass(InboxNotifications::class)]
final class InboxNotificationsTest extends TestCase
{
    private InMemoryUsers $users;
    private InMemoryCompanies $companies;
    private InMemoryMemberships $memberships;
    private InMemoryInbox $inbox;
    private RecordingRealtimePublisher $realtime;

    protected function setUp(): void
    {
        $this->users = new InMemoryUsers();
        $this->companies = new InMemoryCompanies();
        $this->memberships = new InMemoryMemberships();
        $this->inbox = new InMemoryInbox();
        $this->realtime = new RecordingRealtimePublisher();
    }

    public function testAPersonalChannelKeepsOneRowForThatUser(): void
    {
        $user = $this->user('amel@twes.local');

        $this->notifications()->publish(new Notification('user:'.$user->getId()->toRfc4122(), 'membership.added', ['company' => 'Acme']));

        self::assertCount(1, $this->inbox->items);
        $item = $this->inbox->items[0];
        self::assertSame($user, $item->getRecipient());
        self::assertNull($item->getCompany());
        self::assertSame('membership.added', $item->getType());
        self::assertSame(['company' => 'Acme'], $item->getPayload());
        self::assertEquals(new \DateTimeImmutable('2026-09-13 10:00:00'), $item->getCreatedAt());
        self::assertFalse($item->isRead());
    }

    public function testACompanyChannelKeepsOneRowPerMemberOfThatCompanyOnly(): void
    {
        $acme = $this->company('Acme');
        $other = $this->company('Other');
        $amel = $this->member('amel@twes.local', $acme);
        $sami = $this->member('sami@twes.local', $acme);
        $this->member('outsider@twes.local', $other);

        $this->notifications()->publish(new Notification('company:'.$acme->getId()->toRfc4122(), 'invitation.accepted', ['display_name' => 'Nour']));

        $recipients = array_map(static fn ($item) => $item->getRecipient(), $this->inbox->items);
        self::assertSame([$amel, $sami], $recipients);
        foreach ($this->inbox->items as $item) {
            self::assertSame($acme, $item->getCompany());
        }
    }

    public function testThePushCarriesTheTypeAndPayloadOnTheSameChannelAfterTheRowsAreWritten(): void
    {
        $user = $this->user('amel@twes.local');
        $channel = 'user:'.$user->getId()->toRfc4122();
        $rowsAtPush = null;
        $this->realtime->onPush = function () use (&$rowsAtPush): void {
            $rowsAtPush = \count($this->inbox->items);
        };

        $this->notifications()->publish(new Notification($channel, 'membership.added', ['company' => 'Acme']));

        self::assertSame([['channel' => $channel, 'data' => ['type' => 'membership.added', 'payload' => ['company' => 'Acme']]]], $this->realtime->pushed);
        self::assertSame(1, $rowsAtPush, 'the row exists before anybody is told about it');
    }

    /** @return iterable<string, array{string}> */
    public static function malformedChannels(): iterable
    {
        yield 'no kind' => [Uuid::v7()->toRfc4122()];
        yield 'unknown kind' => ['team:'.Uuid::v7()->toRfc4122()];
        yield 'not a uuid' => ['user:42'];
        yield 'trailing text' => ['user:'.Uuid::v7()->toRfc4122().':x'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedChannels')]
    public function testAChannelOfNeitherShapeIsAProgrammingErrorAndNothingIsWritten(string $channel): void
    {
        try {
            $this->notifications()->publish(new Notification($channel, 'membership.added'));
            self::fail('a malformed channel is refused');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame([], $this->inbox->items);
        self::assertSame([], $this->realtime->pushed);
    }

    public function testAnUnknownRecipientIsAContradictionNotASilentDrop(): void
    {
        $this->expectException(\LogicException::class);

        $this->notifications()->publish(new Notification('user:'.Uuid::v7()->toRfc4122(), 'membership.added'));
    }

    public function testAnUnknownCompanyIsAContradictionNotASilentDrop(): void
    {
        $this->expectException(\LogicException::class);

        $this->notifications()->publish(new Notification('company:'.Uuid::v7()->toRfc4122(), 'invitation.accepted'));
    }

    private function notifications(): InboxNotifications
    {
        return new InboxNotifications($this->users, $this->companies, $this->memberships, $this->inbox, $this->realtime, new MockClock('2026-09-13 10:00:00'));
    }

    private function user(string $email): User
    {
        $user = new User(Email::fromString($email), ucfirst(strtok($email, '@') ?: 'User'));
        $this->users->save($user);

        return $user;
    }

    private function company(string $name): Company
    {
        $company = new Company($name, 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->companies->save($company);

        return $company;
    }

    private function member(string $email, Company $company): User
    {
        $user = $this->user($email);
        $this->memberships->save(new Membership($user, $company, new Role(Role::MEMBER, ['company.read'], $company)));

        return $user;
    }
}
