<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Inbox\Domain\InboxItem;
use App\Inbox\Domain\InboxRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryInbox implements InboxRepository
{
    /** @var list<InboxItem> */
    public array $items = [];

    public function add(InboxItem ...$items): void
    {
        foreach ($items as $item) {
            $this->save($item);
        }
    }

    public function latestFor(Uuid $recipientId, int $limit): array
    {
        $mine = $this->of($recipientId);
        usort($mine, static fn (InboxItem $a, InboxItem $b) => [$b->getCreatedAt(), $b->getId()->toRfc4122()] <=> [$a->getCreatedAt(), $a->getId()->toRfc4122()]);

        return \array_slice($mine, 0, $limit);
    }

    public function unreadCountFor(Uuid $recipientId): int
    {
        return \count(array_filter($this->of($recipientId), static fn (InboxItem $item) => !$item->isRead()));
    }

    public function ofRecipient(Uuid $recipientId, Uuid $itemId): ?InboxItem
    {
        foreach ($this->of($recipientId) as $item) {
            if ($item->getId()->equals($itemId)) {
                return $item;
            }
        }

        return null;
    }

    public function markAllReadFor(Uuid $recipientId, \DateTimeImmutable $at): void
    {
        foreach ($this->of($recipientId) as $item) {
            $item->markRead($at);
        }
    }

    public function save(InboxItem $item): void
    {
        if (!\in_array($item, $this->items, true)) {
            $this->items[] = $item;
        }
    }

    /** @return list<InboxItem> */
    private function of(Uuid $recipientId): array
    {
        return array_values(array_filter($this->items, static fn (InboxItem $item) => $item->getRecipient()->getId()->equals($recipientId)));
    }
}
