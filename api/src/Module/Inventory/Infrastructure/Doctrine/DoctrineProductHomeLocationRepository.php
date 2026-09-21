<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Doctrine;

use App\Module\Inventory\Domain\ProductHomeLocation;
use App\Module\Inventory\Domain\ProductHomeLocationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineProductHomeLocationRepository implements ProductHomeLocationRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofProduct(Uuid $productId, Uuid $companyId): array
    {
        /** @var list<ProductHomeLocation> $homes */
        $homes = $this->entityManager->createQueryBuilder()
            ->select('h', 'e', 'l')
            ->from(ProductHomeLocation::class, 'h')
            ->join('h.establishment', 'e')
            ->join('h.location', 'l')
            ->where('h.product = :product')
            ->andWhere('h.company = :company')
            ->setParameter('product', $productId)
            ->setParameter('company', $companyId)
            ->orderBy('e.code', 'ASC')
            ->getQuery()
            ->getResult();

        return $homes;
    }

    public function ofProductInEstablishment(Uuid $productId, Uuid $establishmentId): ?ProductHomeLocation
    {
        return $this->entityManager->getRepository(ProductHomeLocation::class)
            ->findOneBy(['product' => $productId, 'establishment' => $establishmentId]);
    }

    public function ofProducts(array $productIds, Uuid $companyId): array
    {
        if ([] === $productIds) {
            return [];
        }
        $homes = $this->entityManager->getRepository(ProductHomeLocation::class)
            ->findBy(['product' => $productIds, 'company' => $companyId]);

        $byProduct = [];
        foreach ($homes as $home) {
            $byProduct[$home->getProduct()->getId()->toRfc4122()][] = $home;
        }

        return $byProduct;
    }

    public function save(ProductHomeLocation $home): void
    {
        $this->entityManager->persist($home);
        $this->entityManager->flush();
    }

    public function remove(ProductHomeLocation $home): void
    {
        $this->entityManager->remove($home);
        $this->entityManager->flush();
    }
}
