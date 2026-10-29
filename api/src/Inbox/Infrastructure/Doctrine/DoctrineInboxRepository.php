<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Infrastructure\Doctrine;

use App\Inbox\Domain\Model\InboxItem;
use App\Inbox\Domain\Model\NotificationPreference;
use App\Inbox\Domain\Repository\InboxRepository;
use App\Tenancy\Domain\Model\Membership;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
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

    /**
     * A company's notification is its recipient's only while they are still a member of it: removal ends access to the
     * company, what it told them included. One with no company (the account's own, an operator's) always is.
     */
    private const string STILL_A_MEMBER = '(i.company IS NULL OR EXISTS (SELECT 1 FROM '.Membership::class.' m
        WHERE m.user = i.recipient AND m.company = i.company))';

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

    public function latestFor(Uuid $recipientId, int $limit, array $outside = []): array
    {
        // The id breaks ties between rows written in the same second: it is a UUIDv7, so it sorts by time too.
        /** @var list<InboxItem> $items */
        $items = self::leavingOut($this->entityManager->createQuery(
            'SELECT i FROM '.InboxItem::class.' i WHERE i.recipient = :recipient AND '.self::STILL_A_MEMBER.' AND '.self::inside($outside).'
             ORDER BY i.createdAt DESC, i.id DESC',
        ), $outside)->setParameter('recipient', $recipientId, 'uuid')->setMaxResults($limit)->getResult();

        return $items;
    }

    public function unreadCountFor(Uuid $recipientId, array $outside = []): int
    {
        return (int) self::leavingOut($this->entityManager->createQuery(
            'SELECT COUNT(i.id) FROM '.InboxItem::class.' i WHERE i.recipient = :recipient AND i.readAt IS NULL AND '.self::NOT_MUTED.' AND '.self::STILL_A_MEMBER.' AND '.self::inside($outside),
        ), $outside)->setParameter('recipient', $recipientId, 'uuid')->getSingleScalarResult();
    }

    public function unreadByCompanyFor(Uuid $recipientId, array $outside = []): array
    {
        $rows = self::leavingOut($this->entityManager->createQuery(
            'SELECT IDENTITY(i.company) AS company, COUNT(i.id) AS unread FROM '.InboxItem::class.' i
             WHERE i.recipient = :recipient AND i.readAt IS NULL AND i.company IS NOT NULL AND '.self::NOT_MUTED.'
               AND EXISTS (SELECT 1 FROM '.Membership::class.' m WHERE m.user = i.recipient AND m.company = i.company)
               AND '.self::inside($outside).'
             GROUP BY i.company',
        ), $outside)->setParameter('recipient', $recipientId, 'uuid')->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            \assert(\is_array($row) && \is_string($row['company'] ?? null) && is_numeric($row['unread'] ?? null));
            $counts[Uuid::fromString($row['company'])->toRfc4122()] = (int) $row['unread'];
        }

        return $counts;
    }

    public function ofRecipient(Uuid $recipientId, Uuid $itemId, array $outside = []): ?InboxItem
    {
        $item = self::leavingOut($this->entityManager->createQuery(
            'SELECT i FROM '.InboxItem::class.' i WHERE i.id = :id AND i.recipient = :recipient AND '.self::STILL_A_MEMBER.' AND '.self::inside($outside),
        ), $outside)->setParameter('id', $itemId, 'uuid')->setParameter('recipient', $recipientId, 'uuid')->getOneOrNullResult();

        return $item instanceof InboxItem ? $item : null;
    }

    public function markAllReadFor(Uuid $recipientId, \DateTimeImmutable $at, array $outside = []): void
    {
        // One statement rather than a load-and-flush: a busy inbox should not be read into memory to be stamped.
        self::leavingOut($this->entityManager->createQuery(
            'UPDATE '.InboxItem::class.' i SET i.readAt = :at WHERE i.recipient = :recipient AND i.readAt IS NULL AND '.self::STILL_A_MEMBER.' AND '.self::inside($outside),
        ), $outside)
            ->setParameter('at', $at, 'datetime_immutable')
            ->setParameter('recipient', $recipientId, 'uuid')
            ->execute();
    }

    public function save(InboxItem $item): void
    {
        $this->entityManager->persist($item);
        $this->entityManager->flush();
    }

    /** @param list<Uuid> $outside */
    private static function inside(array $outside): string
    {
        return [] === $outside ? 'TRUE = TRUE' : '(i.company IS NULL OR i.company NOT IN (:outside))';
    }

    /**
     * @template TKey
     * @template TResult
     *
     * @param Query<TKey, TResult> $query
     * @param list<Uuid>           $outside
     *
     * @return Query<TKey, TResult>
     */
    private static function leavingOut(Query $query, array $outside): Query
    {
        return [] === $outside ? $query : $query->setParameter('outside', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $outside));
    }
}
