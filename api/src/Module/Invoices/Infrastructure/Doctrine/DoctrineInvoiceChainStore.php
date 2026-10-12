<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Doctrine;

use App\Module\Invoices\Domain\ChainSeries;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceChainStore;
use App\Module\Invoices\Domain\InvoiceType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * The chains, read from the invoice rows: a document is issued once it has a number, and its series is its company,
 * establishment and type. The head is found through uniq_invoice_chain_position, read backwards.
 */
final readonly class DoctrineInvoiceChainStore implements InvoiceChainStore
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function series(?Uuid $companyId, bool $unsealedOnly = false): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(i.company) AS company', 'IDENTITY(i.establishment) AS establishment', 'i.documentType AS type')
            ->distinct()
            ->from(Invoice::class, 'i')
            ->where('i.number IS NOT NULL')
            ->orderBy('company')->addOrderBy('establishment')->addOrderBy('type');
        if (null !== $companyId) {
            $query->andWhere('i.company = :company')->setParameter('company', $companyId, 'uuid');
        }
        if ($unsealedOnly) {
            $query->andWhere('i.chainHash IS NULL');
        }

        $series = [];
        /** @var list<array{company: string, establishment: string, type: InvoiceType|string}> $rows */
        $rows = $query->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $type = $row['type'] instanceof InvoiceType ? $row['type'] : InvoiceType::from($row['type']);
            $series[] = new ChainSeries(Uuid::fromString($row['company']), Uuid::fromString($row['establishment']), $type);
        }

        return $series;
    }

    public function head(ChainSeries $series): ?Invoice
    {
        /** @var Invoice|null $head */
        $head = $this->of($series)
            ->andWhere('i.chainPosition IS NOT NULL')
            ->orderBy('i.chainPosition', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return $head;
    }

    public function unsealed(ChainSeries $series, int $limit): array
    {
        /** @var list<Invoice> $unsealed */
        $unsealed = $this->of($series)
            ->andWhere('i.chainHash IS NULL')
            // The order they were issued in. The moment is kept to the second, so two issued within one tie; within one
            // day a series' numbers share their period and grow one by one, so the number settles it, the shorter first
            // where the counter is not padded (9 before 10). The scale generator numbers in this same order.
            ->addSelect('LENGTH(i.number) AS HIDDEN numberLength')
            ->orderBy('i.issueDate')->addOrderBy('numberLength')->addOrderBy('i.number')->addOrderBy('i.id')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        return $unsealed;
    }

    public function sealed(ChainSeries $series, int $afterPosition, int $limit): array
    {
        /** @var list<Invoice> $sealed */
        $sealed = $this->of($series)
            ->andWhere('i.chainPosition > :after')->setParameter('after', $afterPosition)
            ->orderBy('i.chainPosition')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        return $sealed;
    }

    public function write(): void
    {
        $this->entityManager->flush();
    }

    public function forget(): void
    {
        $this->entityManager->clear();
    }

    private function of(ChainSeries $series): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.company = :company')
            ->andWhere('i.establishment = :establishment')
            ->andWhere('i.documentType = :type')
            ->andWhere('i.number IS NOT NULL')
            ->setParameter('company', $series->companyId, 'uuid')
            ->setParameter('establishment', $series->establishmentId, 'uuid')
            ->setParameter('type', $series->type);
    }
}
