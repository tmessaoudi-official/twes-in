<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Doctrine;

use App\Module\Products\Domain\Model\ProductCostChange;
use App\Module\Products\Domain\Repository\ProductCostChangeRepository;
use App\Module\Products\Domain\ValueObject\CostChangeSource;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineProductCostChangeRepository implements ProductCostChangeRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(ProductCostChange $change): void
    {
        $this->entityManager->persist($change);
        $this->entityManager->flush();
        // A row is only ever added and read back by query, never through the unit of work. Left managed, it would point
        // at a product the product import detaches after its row, and the next flush would take that product for new.
        $this->entityManager->detach($change);
    }

    public function ofProduct(Uuid $productId, Uuid $companyId, int $limit): array
    {
        /** @var list<ProductCostChange> $changes */
        $changes = $this->entityManager->createQueryBuilder()
            ->select('c')
            // A past purchase is placed at its own day, and one whose day is not known after every dated row.
            ->addSelect('CASE WHEN c.source = :purchase AND c.purchasedOn IS NULL THEN 1 ELSE 0 END AS HIDDEN undated')
            ->addSelect('COALESCE(c.purchasedOn, c.at) AS HIDDEN happened')
            ->from(ProductCostChange::class, 'c')
            ->leftJoin('c.vendor', 'v')
            ->addSelect('v')
            ->where('c.product = :product')
            ->andWhere('c.company = :company')
            ->orderBy('undated', \SortDirection::Ascending)
            ->addOrderBy('happened', \SortDirection::Descending)
            ->addOrderBy('c.id', \SortDirection::Descending)
            ->setParameter('purchase', CostChangeSource::Purchase->value)
            ->setParameter('product', $productId, 'uuid')
            ->setParameter('company', $companyId, 'uuid')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $changes;
    }

    public function purchasesFromVendor(Uuid $vendorId, Uuid $companyId, PageRequest $request): Page
    {
        $total = $this->fromVendor($vendorId, $companyId)
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
        \assert(is_numeric($total));
        /** @var list<ProductCostChange> $purchases */
        $purchases = $this->fromVendor($vendorId, $companyId)
            ->select('c')
            ->addSelect('CASE WHEN c.purchasedOn IS NULL THEN 1 ELSE 0 END AS HIDDEN undated')
            ->innerJoin('c.product', 'p')
            ->addSelect('p')
            ->orderBy('undated', \SortDirection::Ascending)
            ->addOrderBy('c.purchasedOn', \SortDirection::Descending)
            ->addOrderBy('c.id', \SortDirection::Descending)
            ->setFirstResult($request->offset())
            ->setMaxResults($request->size)
            ->getQuery()
            ->getResult();

        return new Page($purchases, (int) $total, $request);
    }

    /** The past purchases made from a vendor, in its company. */
    private function fromVendor(Uuid $vendorId, Uuid $companyId): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->from(ProductCostChange::class, 'c')
            ->where('c.vendor = :vendor')
            ->andWhere('c.company = :company')
            ->andWhere('c.source = :purchase')
            ->setParameter('vendor', $vendorId, 'uuid')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('purchase', CostChangeSource::Purchase->value);
    }
}
