<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Infrastructure\Doctrine;

use App\Module\RecurringExpenses\Domain\Model\RecurringExpense;
use App\Module\RecurringExpenses\Domain\Repository\RecurringExpenseRepository;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineRecurringExpenseRepository implements RecurringExpenseRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(RecurringExpense $recurring): void
    {
        $this->entityManager->persist($recurring);
        $this->entityManager->flush();
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?RecurringExpense
    {
        $recurring = $this->entityManager->find(RecurringExpense::class, $id);

        return null !== $recurring && $recurring->getCompany()->getId()->equals($companyId) ? $recurring : null;
    }

    public function lockedOfIdInCompany(Uuid $id, Uuid $companyId): ?RecurringExpense
    {
        $recurring = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(RecurringExpense::class, 'r')
            ->where('r.id = :id')
            ->andWhere('r.company = :company')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('company', $companyId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $recurring instanceof RecurringExpense ? $recurring : null;
    }

    public function ofCompany(Uuid $companyId): array
    {
        $found = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(RecurringExpense::class, 'r')
            ->where('r.company = :company')
            ->setParameter('company', $companyId, 'uuid')
            // The next to draft first, then the ended, the newest of each first.
            ->orderBy('CASE WHEN r.recurrence.nextOn IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('r.recurrence.nextOn', 'ASC')
            ->addOrderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        return Rows::entities($found, RecurringExpense::class);
    }

    public function dueOn(Uuid $companyId, \DateTimeImmutable $day): array
    {
        // Plain SQL escapes the company filter, so the company is named here.
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM recurring_expense WHERE company_id = :company AND paused = false AND next_on IS NOT NULL AND next_on <= :day ORDER BY next_on, id',
            ['company' => $companyId->toRfc4122(), 'day' => $day->format('Y-m-d')],
        );

        return array_map(static fn (mixed $id): Uuid => Uuid::fromString(\is_string($id) ? $id : throw new \UnexpectedValueException('A recurring expense id is a string.')), $ids);
    }
}
