<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Infrastructure\Doctrine;

use App\Shared\Application\RecordChanged;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tenancy\Domain\Model\Company;
use App\Texts\Domain\Model\TextOverride;
use App\Texts\Domain\Repository\TextOverrideRepository;
use App\Texts\Domain\ValueObject\TextAddress;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;

final readonly class DoctrineTextOverrideRepository implements TextOverrideRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function of(?Company $company, string $language): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(TextOverride::class, 't')
            ->where('t.language = :language')
            ->setParameter('language', $language)
            ->orderBy('t.domain')
            ->addOrderBy('t.key');
        self::forLevel($query, $company);

        return Rows::entities($query->getQuery()->getResult(), TextOverride::class);
    }

    public function lockedFind(?Company $company, TextAddress $address): ?TextOverride
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(TextOverride::class, 't')
            ->where('t.language = :language')
            ->andWhere('t.domain = :domain')
            ->andWhere('t.key = :key')
            ->setParameter('language', $address->language)
            ->setParameter('domain', $address->domain)
            ->setParameter('key', $address->key);
        self::forLevel($query, $company);
        $override = $query->getQuery()
            // SELECT … FOR UPDATE; the refresh hint replaces what an earlier read in this request left in memory.
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $override instanceof TextOverride ? $override : null;
    }

    public function save(TextOverride $override): void
    {
        $this->entityManager->persist($override);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $clash) {
            // Two first texts at one address both read no row: the later one was written over a version it never read.
            throw new RecordChanged($clash);
        }
    }

    public function remove(TextOverride $override): void
    {
        $this->entityManager->remove($override);
        $this->entityManager->flush();
    }

    /** The company is named here rather than left to the company filter: the installation's rows have none. */
    private static function forLevel(QueryBuilder $query, ?Company $company): void
    {
        null === $company
            ? $query->andWhere('t.company IS NULL')
            : $query->andWhere('t.company = :company')->setParameter('company', $company->getId(), 'uuid');
    }
}
