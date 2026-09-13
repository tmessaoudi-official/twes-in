<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Inbox\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Inbox\Application\InboxItemNotFound;
use App\Inbox\Application\NotificationCentre;
use App\Inbox\Domain\InboxItem;
use App\Tests\Support\InMemoryInbox;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** What the bell shows and what reading does: always the recipient's own rows, newest first. */
#[CoversClass(NotificationCentre::class)]
final class NotificationCentreTest extends TestCase
{
    private InMemoryInbox $inbox;
    private MockClock $clock;
    private User $amel;
    private User $sami;

    protected function setUp(): void
    {
        $this->inbox = new InMemoryInbox();
        $this->clock = new MockClock('2026-09-13 10:00:00');
        $this->amel = new User(Email::fromString('amel@twes.local'), 'Amel');
        $this->sami = new User(Email::fromString('sami@twes.local'), 'Sami');
    }

    public function testTheLatestPageIsTheRecipientsOwnNewestFirstCappedAtThePageSizeWithTheUnreadCount(): void
    {
        $first = $this->item($this->amel, '2026-09-13 09:00:00');
        $second = $this->item($this->amel, '2026-09-13 09:10:00');
        $third = $this->item($this->amel, '2026-09-13 09:20:00');
        $this->item($this->sami, '2026-09-13 09:30:00');
        $first->markRead(new \DateTimeImmutable('2026-09-13 09:05:00'));

        $page = $this->centre(2)->latest($this->amel->getId());

        self::assertSame([$third, $second], $page->items);
        self::assertSame(2, $page->unread, 'the count covers every unread row, not only the page');
    }

    public function testReadingAnItemStampsItOnce(): void
    {
        $item = $this->item($this->amel, '2026-09-13 09:00:00');

        $this->centre()->markRead($this->amel->getId(), $item->getId());
        $this->clock->sleep(60);
        $this->centre()->markRead($this->amel->getId(), $item->getId());

        self::assertEquals(new \DateTimeImmutable('2026-09-13 10:00:00'), $item->getReadAt());
    }

    public function testSomeoneElsesItemIsNotFoundAndStaysUnread(): void
    {
        $item = $this->item($this->sami, '2026-09-13 09:00:00');

        try {
            $this->centre()->markRead($this->amel->getId(), $item->getId());
            self::fail('another person\'s item is refused');
        } catch (InboxItemNotFound) {
        }

        self::assertFalse($item->isRead());
    }

    public function testAnItemThatDoesNotExistIsNotFound(): void
    {
        $this->expectException(InboxItemNotFound::class);

        $this->centre()->markRead($this->amel->getId(), Uuid::v7());
    }

    public function testReadingAllTouchesOnlyTheRecipientsRows(): void
    {
        $mine = [$this->item($this->amel, '2026-09-13 09:00:00'), $this->item($this->amel, '2026-09-13 09:10:00')];
        $theirs = $this->item($this->sami, '2026-09-13 09:20:00');

        $this->centre()->markAllRead($this->amel->getId());

        foreach ($mine as $item) {
            self::assertTrue($item->isRead());
        }
        self::assertFalse($theirs->isRead());
        self::assertSame(0, $this->centre()->latest($this->amel->getId())->unread);
    }

    public function testAPageSizeBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->centre(0);
    }

    private function centre(int $pageSize = 50): NotificationCentre
    {
        return new NotificationCentre($this->inbox, $this->clock, $pageSize);
    }

    private function item(User $recipient, string $at): InboxItem
    {
        $item = new InboxItem($recipient, null, 'membership.added', [], new \DateTimeImmutable($at));
        $this->inbox->add($item);

        return $item;
    }
}
