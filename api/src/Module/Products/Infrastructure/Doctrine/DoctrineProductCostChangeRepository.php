<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Doctrine;

use App\Module\Products\Domain\ProductCostChange;
use App\Module\Products\Domain\ProductCostChangeRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        return $this->entityManager->getRepository(ProductCostChange::class)->findBy(['product' => $productId, 'company' => $companyId], ['at' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
