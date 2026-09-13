<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Infrastructure\Doctrine;

use App\Inbox\Domain\InboxItem;
use App\Inbox\Domain\InboxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineInboxRepository implements InboxRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(InboxItem ...$items): void
    {
        foreach ($items as $item) {
            $this->entityManager->persist($item);
        }
        $this->entityManager->flush();
    }

    public function latestFor(Uuid $recipientId, int $limit): array
    {
        // The id breaks ties between rows written in the same second: it is a UUIDv7, so it sorts by time too.
        return $this->entityManager->getRepository(InboxItem::class)->findBy(
            ['recipient' => $recipientId],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
            $limit,
        );
    }

    public function unreadCountFor(Uuid $recipientId): int
    {
        return (int) $this->entityManager->createQuery(
            'SELECT COUNT(i.id) FROM '.InboxItem::class.' i WHERE i.recipient = :recipient AND i.readAt IS NULL',
        )->setParameter('recipient', $recipientId, 'uuid')->getSingleScalarResult();
    }

    public function ofRecipient(Uuid $recipientId, Uuid $itemId): ?InboxItem
    {
        return $this->entityManager->getRepository(InboxItem::class)->findOneBy(['id' => $itemId, 'recipient' => $recipientId]);
    }

    public function markAllReadFor(Uuid $recipientId, \DateTimeImmutable $at): void
    {
        // One statement rather than a load-and-flush: a busy inbox should not be read into memory to be stamped.
        $this->entityManager->createQuery(
            'UPDATE '.InboxItem::class.' i SET i.readAt = :at WHERE i.recipient = :recipient AND i.readAt IS NULL',
        )
            ->setParameter('at', $at, 'datetime_immutable')
            ->setParameter('recipient', $recipientId, 'uuid')
            ->execute();
    }

    public function save(InboxItem $item): void
    {
        $this->entityManager->persist($item);
        $this->entityManager->flush();
    }
}
