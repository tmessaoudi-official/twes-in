<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Infrastructure\Doctrine;

use App\Module\Recurring\Domain\RecurringInvoice;
use App\Module\Recurring\Domain\RecurringInvoiceRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineRecurringInvoiceRepository implements RecurringInvoiceRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(RecurringInvoice $recurring): void
    {
        $this->entityManager->persist($recurring);
        $this->entityManager->flush();
    }

    public function remove(RecurringInvoice $recurring): void
    {
        $this->entityManager->remove($recurring);
        $this->entityManager->flush();
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?RecurringInvoice
    {
        $recurring = $this->entityManager->find(RecurringInvoice::class, $id);

        return null !== $recurring && $recurring->getCompany()->getId()->equals($companyId) ? $recurring : null;
    }

    public function lockedOfIdInCompany(Uuid $id, Uuid $companyId): ?RecurringInvoice
    {
        $recurring = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(RecurringInvoice::class, 'r')
            ->where('r.id = :id')
            ->andWhere('r.company = :company')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('company', $companyId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $recurring instanceof RecurringInvoice ? $recurring : null;
    }

    public function ofCompany(Uuid $companyId): array
    {
        $found = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(RecurringInvoice::class, 'r')
            ->where('r.company = :company')
            ->setParameter('company', $companyId, 'uuid')
            // The next to draft first, then the ended, the newest of each first.
            ->orderBy('CASE WHEN r.nextOn IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('r.nextOn', 'ASC')
            ->addOrderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(\is_array($found) ? $found : [], static fn (mixed $row): bool => $row instanceof RecurringInvoice));
    }

    public function dueOn(Uuid $companyId, \DateTimeImmutable $day): array
    {
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM recurring_invoice WHERE company_id = :company AND paused = false AND next_on IS NOT NULL AND next_on <= :day ORDER BY next_on, id',
            ['company' => $companyId->toRfc4122(), 'day' => $day->format('Y-m-d')],
        );

        return array_map(static fn (mixed $id): Uuid => Uuid::fromString(\is_string($id) ? $id : throw new \UnexpectedValueException('A recurring invoice id is a string.')), $ids);
    }
}
