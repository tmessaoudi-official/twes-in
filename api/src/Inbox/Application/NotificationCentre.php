<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Application;

use App\Inbox\Domain\InboxRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** What the bell shows and what reading does. Every call is scoped to the recipient: there is no other way in. */
final readonly class NotificationCentre
{
    public function __construct(
        private InboxRepository $inbox,
        private ClockInterface $clock,
        private int $pageSize,
    ) {
        if ($pageSize < 1) {
            throw new \InvalidArgumentException(\sprintf('The notification page size must be at least 1, %d given.', $pageSize));
        }
    }

    public function latest(Uuid $recipientId): InboxPage
    {
        return new InboxPage(
            $this->inbox->latestFor($recipientId, $this->pageSize),
            $this->inbox->unreadCountFor($recipientId),
        );
    }

    /** @throws InboxItemNotFound when the item does not exist or belongs to somebody else, which look the same */
    public function markRead(Uuid $recipientId, Uuid $itemId): void
    {
        $item = $this->inbox->ofRecipient($recipientId, $itemId) ?? throw new InboxItemNotFound();

        $item->markRead($this->clock->now());
        $this->inbox->save($item);
    }

    public function markAllRead(Uuid $recipientId): void
    {
        $this->inbox->markAllReadFor($recipientId, $this->clock->now());
    }
}
