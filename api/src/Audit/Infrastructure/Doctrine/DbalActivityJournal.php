<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure\Doctrine;

use App\Audit\Application\ActivityEntry;
use App\Audit\Application\ActivityJournal;
use App\Audit\Application\ActivitySearch;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Shared\Infrastructure\Doctrine\SearchText;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * The journal read in plain SQL over `audit_log`, which no company filter scopes: every query names its company
 * itself. The author's name comes from the account, joined here rather than asked of the identity context per row.
 */
final readonly class DbalActivityJournal implements ActivityJournal
{
    public function __construct(private Connection $connection)
    {
    }

    public function page(Uuid $companyId, ActivitySearch $search, \DateTimeImmutable $since, PageRequest $page): Page
    {
        $count = $this->narrowed($companyId, $search, $since)->select('COUNT(*)')->executeQuery()->fetchOne();
        $direction = 'asc' === $search->direction ? 'ASC' : 'DESC';
        $rows = $this->narrowed($companyId, $search, $since)
            ->select('a.id', 'a.at', 'a.action', 'a.entity_type', 'a.entity_id', 'a.actor_user_id', 'a.changes', 'a.ip', 'u.display_name')
            ->orderBy('a.at', $direction)->addOrderBy('a.id', $direction)
            ->setFirstResult($page->offset())->setMaxResults($page->size)
            ->executeQuery()->fetchAllAssociative();

        return new Page(array_map(self::entry(...), $rows), is_numeric($count) ? (int) $count : throw new \UnexpectedValueException('The journal could not count its entries.'), $page);
    }

    private function narrowed(Uuid $companyId, ActivitySearch $search, \DateTimeImmutable $since): QueryBuilder
    {
        $query = $this->connection->createQueryBuilder()
            ->from('audit_log', 'a')
            ->leftJoin('a', '"user"', 'u', 'u.id = a.actor_user_id')
            ->where('a.company_id = :company')->setParameter('company', $companyId->toRfc4122())
            ->andWhere('a.at >= :since')->setParameter('since', self::utc($since));
        $words = trim($search->text ?? '');
        if ('' !== $words) {
            $query->andWhere('(a.action ILIKE :words OR a.entity_type ILIKE :words OR u.display_name ILIKE :words)')
                ->setParameter('words', '%'.SearchText::escapeLike($words).'%');
        }
        if ([] !== $search->actorIds) {
            $query->andWhere('a.actor_user_id IN (:actors)')->setParameter('actors', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $search->actorIds), ArrayParameterType::STRING);
        }
        if ([] !== $search->entityTypes) {
            $query->andWhere('a.entity_type IN (:types)')->setParameter('types', $search->entityTypes, ArrayParameterType::STRING);
        }
        if (null !== $search->entityId) {
            $query->andWhere('a.entity_id = :entity')->setParameter('entity', $search->entityId->toRfc4122());
        }
        if ([] !== $search->actions) {
            $query->andWhere('a.action IN (:actions)')->setParameter('actions', $search->actions, ArrayParameterType::STRING);
        }
        // A day is the company's own, from its first instant to the next day's, and the column holds UTC.
        $zone = new \DateTimeZone($search->timezone);
        if (null !== $search->days?->from) {
            $query->andWhere('a.at >= :from')->setParameter('from', self::utc(new \DateTimeImmutable($search->days->from, $zone)));
        }
        if (null !== $search->days?->to) {
            $query->andWhere('a.at < :before')->setParameter('before', self::utc(new \DateTimeImmutable($search->days->to, $zone)->modify('+1 day')));
        }

        return $query;
    }

    private static function utc(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row */
    private static function entry(array $row): ActivityEntry
    {
        $changes = json_decode(self::text($row['changes']), true, 32, \JSON_THROW_ON_ERROR);
        $entityId = $row['entity_id'];
        $actorId = $row['actor_user_id'];
        $name = $row['display_name'];
        $ip = $row['ip'];

        return new ActivityEntry(
            Uuid::fromString(self::text($row['id'])),
            new \DateTimeImmutable(self::text($row['at']), new \DateTimeZone('UTC')),
            self::text($row['action']),
            self::text($row['entity_type']),
            \is_string($entityId) ? Uuid::fromString($entityId) : null,
            \is_string($actorId) ? Uuid::fromString($actorId) : null,
            \is_string($name) ? $name : null,
            ActivityEntry::fieldsOf(\is_array($changes) ? $changes : []),
            \is_string($ip) ? $ip : null,
        );
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('The journal read a column that is not text.');
    }
}
