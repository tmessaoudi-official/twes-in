<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Doctrine;

use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineStockLocationRepository implements StockLocationRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        return $this->entityManager->getRepository(StockLocation::class)->findBy(['company' => $companyId], ['code' => 'ASC', 'id' => 'ASC']);
    }

    public function ofIdsInCompany(array $ids, Uuid $companyId): array
    {
        return [] === $ids ? [] : $this->entityManager->getRepository(StockLocation::class)->findBy(['id' => $ids, 'company' => $companyId]);
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?StockLocation
    {
        $location = $this->entityManager->find(StockLocation::class, $id);

        return null !== $location && $location->getCompany()->getId()->equals($companyId) ? $location : null;
    }

    public function defaultOf(Uuid $establishmentId): ?StockLocation
    {
        return $this->entityManager->getRepository(StockLocation::class)->findOneBy(['establishment' => $establishmentId, 'isDefault' => true]);
    }

    public function ofCodeInEstablishment(string $code, Uuid $establishmentId): ?StockLocation
    {
        return $this->entityManager->getRepository(StockLocation::class)->findOneBy(['establishment' => $establishmentId, 'code' => $code]);
    }

    public function ofCodeInCompany(string $code, Uuid $companyId): array
    {
        return $this->entityManager->getRepository(StockLocation::class)->findBy(['company' => $companyId, 'code' => $code], ['id' => 'ASC']);
    }

    public function countChildren(Uuid $locationId): int
    {
        return $this->entityManager->getRepository(StockLocation::class)->count(['parent' => $locationId]);
    }

    public function save(StockLocation $location): void
    {
        $this->entityManager->persist($location);
        $this->entityManager->flush();
    }

    public function remove(StockLocation $location): void
    {
        $this->entityManager->remove($location);
        $this->entityManager->flush();
    }
}
