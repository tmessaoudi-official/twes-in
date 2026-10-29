<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Inbox\Domain\Model\InboxItem;
use App\Inbox\Domain\Repository\InboxRepository;
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

    public function latestFor(Uuid $recipientId, int $limit, array $outside = []): array
    {
        $mine = $this->of($recipientId, $outside);
        usort($mine, static fn (InboxItem $a, InboxItem $b) => [$b->getCreatedAt(), $b->getId()->toRfc4122()] <=> [$a->getCreatedAt(), $a->getId()->toRfc4122()]);

        return \array_slice($mine, 0, $limit);
    }

    public function unreadCountFor(Uuid $recipientId, array $outside = []): int
    {
        return \count(array_filter($this->of($recipientId, $outside), static fn (InboxItem $item) => !$item->isRead()));
    }

    public function unreadByCompanyFor(Uuid $recipientId, array $outside = []): array
    {
        $counts = [];
        foreach ($this->of($recipientId, $outside) as $item) {
            $company = $item->getCompany();
            if (!$item->isRead() && null !== $company) {
                $key = $company->getId()->toRfc4122();
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function ofRecipient(Uuid $recipientId, Uuid $itemId, array $outside = []): ?InboxItem
    {
        foreach ($this->of($recipientId, $outside) as $item) {
            if ($item->getId()->equals($itemId)) {
                return $item;
            }
        }

        return null;
    }

    public function markAllReadFor(Uuid $recipientId, \DateTimeImmutable $at, array $outside = []): void
    {
        foreach ($this->of($recipientId, $outside) as $item) {
            $item->markRead($at);
        }
    }

    public function save(InboxItem $item): void
    {
        if (!\in_array($item, $this->items, true)) {
            $this->items[] = $item;
        }
    }

    /**
     * @param list<Uuid> $outside
     *
     * @return list<InboxItem>
     */
    private function of(Uuid $recipientId, array $outside = []): array
    {
        $left = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $outside);

        return array_values(array_filter($this->items, static fn (InboxItem $item) => $item->getRecipient()->getId()->equals($recipientId)
            && !\in_array($item->getCompany()?->getId()->toRfc4122(), $left, true)));
    }
}
