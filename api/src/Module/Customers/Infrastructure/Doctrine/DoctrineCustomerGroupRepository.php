<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Doctrine;

use App\Module\Customers\Domain\CustomerGroup;
use App\Module\Customers\Domain\CustomerGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineCustomerGroupRepository implements CustomerGroupRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        return $this->entityManager->getRepository(CustomerGroup::class)->findBy(['company' => $companyId], ['name' => 'ASC']);
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?CustomerGroup
    {
        $group = $this->entityManager->find(CustomerGroup::class, $id);

        return null !== $group && $group->getCompany()->getId()->equals($companyId) ? $group : null;
    }

    public function ofNameInCompany(string $name, Uuid $companyId): ?CustomerGroup
    {
        return $this->entityManager->getRepository(CustomerGroup::class)->findOneBy(['company' => $companyId, 'name' => $name]);
    }

    public function save(CustomerGroup $group): void
    {
        $this->entityManager->persist($group);
        $this->entityManager->flush();
    }

    public function remove(CustomerGroup $group): void
    {
        $this->entityManager->remove($group);
        $this->entityManager->flush();
    }
}
