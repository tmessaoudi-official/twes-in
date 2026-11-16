<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Doctrine;

use App\Module\Products\Domain\Model\ProductPhoto;
use App\Module\Products\Domain\Repository\ProductPhotoRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineProductPhotoRepository implements ProductPhotoRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofProduct(Uuid $productId, Uuid $companyId): array
    {
        /** @var list<ProductPhoto> $photos */
        $photos = $this->entityManager->createQueryBuilder()
            ->select('p')->from(ProductPhoto::class, 'p')
            ->where('p.product = :product')->andWhere('p.company = :company')->andWhere('p.removedAt IS NULL')
            // A photo put back keeps its place, which another may have taken meanwhile: the older one comes first.
            ->orderBy('p.position', \SortDirection::Ascending)->addOrderBy('p.createdAt', \SortDirection::Ascending)->addOrderBy('p.id', \SortDirection::Ascending)
            ->setParameter('product', $productId, 'uuid')->setParameter('company', $companyId, 'uuid')
            ->getQuery()->getResult();

        return $photos;
    }

    public function ofIdInProduct(Uuid $id, Uuid $productId, Uuid $companyId): ?ProductPhoto
    {
        return $this->entityManager->getRepository(ProductPhoto::class)->findOneBy(['id' => $id, 'product' => $productId, 'company' => $companyId]);
    }

    public function mainPhotoIdsOf(Uuid $companyId, array $productIds): array
    {
        if ([] === $productIds) {
            return [];
        }
        /** @var list<array{productId: string|Uuid, id: Uuid}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(p.product) AS productId', 'p.id')->from(ProductPhoto::class, 'p')
            ->where('p.company = :company')->andWhere('p.product IN (:products)')->andWhere('p.isMain = true')->andWhere('p.removedAt IS NULL')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('products', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $productIds), ArrayParameterType::STRING)
            ->getQuery()->getResult();
        $main = [];
        foreach ($rows as $row) {
            $productId = $row['productId'];
            $main[$productId instanceof Uuid ? $productId->toRfc4122() : Uuid::fromString($productId)->toRfc4122()] = $row['id']->toRfc4122();
        }

        return $main;
    }

    public function lockProduct(Uuid $productId): void
    {
        $this->entityManager->getConnection()->executeStatement('SELECT 1 FROM product WHERE id = ? FOR UPDATE', [$productId->toRfc4122()]);
    }

    public function save(ProductPhoto $photo): void
    {
        $this->entityManager->persist($photo);
        $this->entityManager->flush();
    }

    public function removedBefore(\DateTimeImmutable $before, int $limit): array
    {
        /** @var list<array{company: string|Uuid, product: string|Uuid, id: Uuid}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(p.company) AS company', 'IDENTITY(p.product) AS product', 'p.id')->from(ProductPhoto::class, 'p')
            ->where('p.removedAt IS NOT NULL')->andWhere('p.removedAt < :before')
            ->orderBy('p.removedAt', \SortDirection::Ascending)->addOrderBy('p.id', \SortDirection::Ascending)
            ->setParameter('before', $before, 'datetime_immutable')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        return array_map(static fn (array $row): array => [
            'company' => $row['company'] instanceof Uuid ? $row['company'] : Uuid::fromString($row['company']),
            'product' => $row['product'] instanceof Uuid ? $row['product'] : Uuid::fromString($row['product']),
            'id' => $row['id'],
        ], $rows);
    }

    public function lockedRemoved(Uuid $companyId, Uuid $id): ?ProductPhoto
    {
        $photo = $this->entityManager->createQueryBuilder()
            ->select('p')->from(ProductPhoto::class, 'p')
            ->where('p.id = :id')->andWhere('p.company = :company')->andWhere('p.removedAt IS NOT NULL')
            ->setParameter('id', $id, 'uuid')->setParameter('company', $companyId, 'uuid')
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();

        return $photo instanceof ProductPhoto ? $photo : null;
    }

    public function delete(ProductPhoto $photo): void
    {
        $this->entityManager->remove($photo);
        $this->entityManager->flush();
    }
}
