<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Doctrine;

use App\Module\Inventory\Domain\ProductHomeLocation;
use App\Module\Inventory\Domain\ProductHomeLocationRepository;
use Doctrine\DBAL\ArrayParameterType;
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
            ->orderBy('e.code', 'ASC')->addOrderBy('h.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $homes;
    }

    public function mainLocationIdsOf(Uuid $productId, Uuid $companyId): array
    {
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT location_id FROM product_home_location WHERE product_id = ? AND company_id = ? AND position = 0 ORDER BY location_id',
            [$productId->toRfc4122(), $companyId->toRfc4122()],
        );

        return array_map(static fn (mixed $id): Uuid => Uuid::fromString(\is_string($id) ? $id : throw new \UnexpectedValueException('A location id is a string.')), $ids);
    }

    public function ofProductInEstablishment(Uuid $productId, Uuid $establishmentId): array
    {
        return $this->entityManager->getRepository(ProductHomeLocation::class)
            ->findBy(['product' => $productId, 'establishment' => $establishmentId], ['position' => 'ASC']);
    }

    public function ofProducts(array $productIds, Uuid $companyId): array
    {
        if ([] === $productIds) {
            return [];
        }
        $homes = $this->entityManager->getRepository(ProductHomeLocation::class)
            ->findBy(['product' => $productIds, 'company' => $companyId], ['position' => 'ASC']);

        $byProduct = [];
        foreach ($homes as $home) {
            $byProduct[$home->getProduct()->getId()->toRfc4122()][] = $home;
        }

        return $byProduct;
    }

    public function atLocations(array $locationIds, Uuid $companyId): array
    {
        if ([] === $locationIds) {
            return [];
        }
        /** @var list<ProductHomeLocation> $homes */
        $homes = $this->entityManager->createQueryBuilder()
            ->select('h', 'p', 'l')
            ->from(ProductHomeLocation::class, 'h')
            ->join('h.product', 'p')
            ->join('h.location', 'l')
            ->where('h.location IN (:locations)')
            ->andWhere('h.company = :company')
            ->andWhere('p.isActive = true')
            ->setParameter('locations', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $locationIds), ArrayParameterType::STRING)
            ->setParameter('company', $companyId)
            ->orderBy('l.code', 'ASC')->addOrderBy('p.reference', 'ASC')
            ->getQuery()
            ->getResult();

        return $homes;
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
