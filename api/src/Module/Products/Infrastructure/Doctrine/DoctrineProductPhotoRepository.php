<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Doctrine;

use App\Module\Products\Domain\ProductPhoto;
use App\Module\Products\Domain\ProductPhotoRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
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
            ->orderBy('p.position', 'ASC')->addOrderBy('p.createdAt', 'ASC')->addOrderBy('p.id', 'ASC')
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
}
