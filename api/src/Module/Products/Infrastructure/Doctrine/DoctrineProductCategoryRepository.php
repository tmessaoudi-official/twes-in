<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Doctrine;

use App\Module\Products\Domain\ProductCategory;
use App\Module\Products\Domain\ProductCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineProductCategoryRepository implements ProductCategoryRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        return $this->entityManager->getRepository(ProductCategory::class)->findBy(['company' => $companyId], ['name' => 'ASC']);
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?ProductCategory
    {
        $category = $this->entityManager->find(ProductCategory::class, $id);

        return null !== $category && $category->getCompany()->getId()->equals($companyId) ? $category : null;
    }

    public function ofNameInCompany(string $name, Uuid $companyId): ?ProductCategory
    {
        return $this->entityManager->getRepository(ProductCategory::class)->findOneBy(['company' => $companyId, 'name' => $name]);
    }

    public function countChildren(Uuid $categoryId): int
    {
        return $this->entityManager->getRepository(ProductCategory::class)->count(['parent' => $categoryId]);
    }

    public function save(ProductCategory $category): void
    {
        $this->entityManager->persist($category);
        $this->entityManager->flush();
    }

    public function remove(ProductCategory $category): void
    {
        $this->entityManager->remove($category);
        $this->entityManager->flush();
    }
}
