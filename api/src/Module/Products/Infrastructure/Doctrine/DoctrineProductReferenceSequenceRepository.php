<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Doctrine;

use App\Module\Products\Domain\ProductReferenceSequence;
use App\Module\Products\Domain\ProductReferenceSequenceRepository;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineProductReferenceSequenceRepository implements ProductReferenceSequenceRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function of(Uuid $companyId): ?ProductReferenceSequence
    {
        return $this->entityManager->getRepository(ProductReferenceSequence::class)->findOneBy(['company' => $companyId]);
    }

    public function lockedFor(Company $company): ProductReferenceSequence
    {
        // Made here rather than read-then-persisted: two first saves at once would both find none and the second
        // would fail on the unique company; the insert that loses the race does nothing, and both then wait on one row.
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO product_reference_sequence (id, company_id, next_number) VALUES (:id, :company, 1) ON CONFLICT (company_id) DO NOTHING',
            ['id' => Uuid::v7()->toRfc4122(), 'company' => $company->getId()->toRfc4122()],
        );

        /** @var ProductReferenceSequence $sequence */
        $sequence = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(ProductReferenceSequence::class, 's')
            ->where('s.company = :company')
            ->setParameter('company', $company->getId(), 'uuid')
            ->getQuery()
            // SELECT … FOR UPDATE, which Doctrine refuses outside a transaction; the refresh hint replaces what an
            // earlier read in this request left in memory with what the lock just read.
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getSingleResult();

        return $sequence;
    }

    public function save(ProductReferenceSequence $sequence): void
    {
        $this->entityManager->persist($sequence);
        $this->entityManager->flush();
    }
}
