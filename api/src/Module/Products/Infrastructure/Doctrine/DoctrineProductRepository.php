<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Doctrine;

use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineProductRepository implements ProductRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        return $this->entityManager->getRepository(Product::class)->findBy(['company' => $companyId], ['reference' => 'ASC']);
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Product
    {
        $product = $this->entityManager->find(Product::class, $id);

        return null !== $product && $product->getCompany()->getId()->equals($companyId) ? $product : null;
    }

    public function ofReferenceInCompany(string $reference, Uuid $companyId): ?Product
    {
        return $this->entityManager->getRepository(Product::class)->findOneBy(['company' => $companyId, 'reference' => $reference]);
    }

    public function countInCategory(Uuid $categoryId): int
    {
        return $this->entityManager->getRepository(Product::class)->count(['category' => $categoryId]);
    }

    public function save(Product $product): void
    {
        $this->entityManager->persist($product);
        $this->entityManager->flush();
    }
}
