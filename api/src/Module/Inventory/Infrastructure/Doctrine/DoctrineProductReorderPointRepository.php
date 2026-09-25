<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Doctrine;

use App\Module\Inventory\Domain\ProductReorderPoint;
use App\Module\Inventory\Domain\ProductReorderPointRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineProductReorderPointRepository implements ProductReorderPointRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofProduct(Uuid $productId, Uuid $companyId): array
    {
        /** @var list<ProductReorderPoint> $points */
        $points = $this->entityManager->createQueryBuilder()
            ->select('p', 'e')
            ->from(ProductReorderPoint::class, 'p')
            ->join('p.establishment', 'e')
            ->where('p.product = :product')
            ->andWhere('p.company = :company')
            ->setParameter('product', $productId)
            ->setParameter('company', $companyId)
            ->orderBy('e.code', 'ASC')
            ->getQuery()
            ->getResult();

        return $points;
    }

    public function ofProductInEstablishment(Uuid $productId, Uuid $establishmentId): ?ProductReorderPoint
    {
        return $this->entityManager->getRepository(ProductReorderPoint::class)
            ->findOneBy(['product' => $productId, 'establishment' => $establishmentId]);
    }

    public function save(ProductReorderPoint $point): void
    {
        $this->entityManager->persist($point);
        $this->entityManager->flush();
    }

    public function remove(ProductReorderPoint $point): void
    {
        $this->entityManager->remove($point);
        $this->entityManager->flush();
    }
}
