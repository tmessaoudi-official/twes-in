<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Infrastructure\Doctrine;

use App\Inbox\Domain\InboxItem;
use App\Inbox\Domain\InboxRepository;
use App\Inbox\Domain\NotificationPreference;
use App\Tenancy\Domain\Membership;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineInboxRepository implements InboxRepository
{
    /**
     * A kind its recipient muted in that company, or muted as a personal kind, is still listed but never counted: the
     * choice holds for what was told before it as much as after.
     */
    private const string NOT_MUTED = 'NOT EXISTS (SELECT 1 FROM '.NotificationPreference::class.' p
        WHERE p.user = i.recipient AND p.type = i.type AND p.bell = false
          AND (p.company = i.company OR (p.company IS NULL AND i.company IS NULL)))';

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
            'SELECT COUNT(i.id) FROM '.InboxItem::class.' i WHERE i.recipient = :recipient AND i.readAt IS NULL AND '.self::NOT_MUTED,
        )->setParameter('recipient', $recipientId, 'uuid')->getSingleScalarResult();
    }

    public function unreadByCompanyFor(Uuid $recipientId): array
    {
        $rows = $this->entityManager->createQuery(
            'SELECT IDENTITY(i.company) AS company, COUNT(i.id) AS unread FROM '.InboxItem::class.' i
             WHERE i.recipient = :recipient AND i.readAt IS NULL AND i.company IS NOT NULL AND '.self::NOT_MUTED.'
               AND EXISTS (SELECT 1 FROM '.Membership::class.' m WHERE m.user = i.recipient AND m.company = i.company)
             GROUP BY i.company',
        )->setParameter('recipient', $recipientId, 'uuid')->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            \assert(\is_array($row) && \is_string($row['company'] ?? null) && is_numeric($row['unread'] ?? null));
            $counts[Uuid::fromString($row['company'])->toRfc4122()] = (int) $row['unread'];
        }

        return $counts;
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
