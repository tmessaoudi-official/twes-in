<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\Doctrine;

use App\ImportExport\Domain\Model\ImportRun;
use App\ImportExport\Domain\Repository\ImportRunRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineImportRunRepository implements ImportRunRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function lastOf(Uuid $companyId, string $subject, string $contentHash): ?ImportRun
    {
        /** @var ImportRun|null $run */
        $run = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ImportRun::class, 'r')
            ->where('r.company = :company')
            ->andWhere('r.subject = :subject')
            ->andWhere('r.contentHash = :hash')
            ->andWhere('r.undoneAt IS NULL')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('subject', $subject)
            ->setParameter('hash', $contentHash)
            ->orderBy('r.at', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $run;
    }

    public function lastPairingOf(Uuid $companyId, string $subject): array
    {
        /** @var ImportRun|null $run */
        $run = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ImportRun::class, 'r')
            ->where('r.company = :company')
            ->andWhere('r.subject = :subject')
            ->andWhere('r.pairing IS NOT NULL')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('subject', $subject)
            ->orderBy('r.at', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $run?->getPairing() ?? [];
    }

    public function save(ImportRun $run): void
    {
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        // Nothing reads it back in the request that wrote it, and an import's unit of work is kept small on purpose.
        $this->entityManager->detach($run);
    }

    public function ofId(Uuid $companyId, Uuid $id): ?ImportRun
    {
        return $this->entityManager->getRepository(ImportRun::class)->findOneBy(['company' => $companyId, 'id' => $id]);
    }

    public function lockedOfId(Uuid $companyId, Uuid $id): ?ImportRun
    {
        /** @var ImportRun|null $run */
        $run = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ImportRun::class, 'r')
            ->where('r.company = :company')
            ->andWhere('r.id = :id')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $run;
    }

    public function ofUndoRemoval(Uuid $companyId, Uuid $removalId): ?ImportRun
    {
        /** @var ImportRun|null $run */
        $run = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ImportRun::class, 'r')
            ->where('r.company = :company')
            ->andWhere('r.undoRemovalId = :removal')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('removal', $removalId, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $run;
    }

    public function since(Uuid $companyId, array $subjects, \DateTimeImmutable $since): array
    {
        if ([] === $subjects) {
            return [];
        }
        /** @var list<ImportRun> $runs */
        $runs = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ImportRun::class, 'r')
            ->where('r.company = :company')
            ->andWhere('r.subject IN (:subjects)')
            ->andWhere('r.at >= :since')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('subjects', $subjects)
            ->setParameter('since', $since)
            ->orderBy('r.at', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $runs;
    }

    public function saveCreated(Uuid $companyId, Uuid $runId, array $created): void
    {
        $connection = $this->entityManager->getConnection();
        foreach ($created as $table => $ids) {
            if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a table name.', $table));
            }
            // Five thousand rows at most, sent a few hundred a statement.
            foreach (array_chunk($ids, 500) as $chunk) {
                $values = $parameters = [];
                foreach ($chunk as $index => $id) {
                    $values[] = \sprintf('(:id%1$d, :company, :run, :table, :record%1$d)', $index);
                    $parameters['id'.$index] = Uuid::v7()->toRfc4122();
                    $parameters['record'.$index] = $id->toRfc4122();
                }
                $connection->executeStatement(
                    'INSERT INTO import_run_record (id, company_id, run_id, table_name, record_id) VALUES '.implode(', ', $values),
                    [...$parameters, 'company' => $companyId->toRfc4122(), 'run' => $runId->toRfc4122(), 'table' => $table],
                );
            }
        }
    }

    public function createdBy(Uuid $companyId, Uuid $runId): array
    {
        $created = [];
        foreach ($this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT table_name, record_id FROM import_run_record WHERE company_id = :company AND run_id = :run ORDER BY table_name, id',
            ['company' => $companyId->toRfc4122(), 'run' => $runId->toRfc4122()],
        ) as $row) {
            if (!\is_string($row['table_name']) || !\is_string($row['record_id'])) {
                throw new \LogicException('A created record names its table and its id.');
            }
            $created[$row['table_name']][] = Uuid::fromString($row['record_id']);
        }

        return $created;
    }
}
