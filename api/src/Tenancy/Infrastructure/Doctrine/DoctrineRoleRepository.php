<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Doctrine;

use App\Tenancy\Domain\Role;
use App\Tenancy\Domain\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineRoleRepository implements RoleRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function builtIn(string $name): ?Role
    {
        return $this->entityManager->getRepository(Role::class)->findOneBy(['name' => $name, 'company' => null]);
    }

    public function ofIdForCompany(Uuid $roleId, Uuid $companyId): ?Role
    {
        $role = $this->entityManager->getRepository(Role::class)->find($roleId);
        if (null === $role) {
            return null;
        }

        return $role->isBuiltIn() || true === $role->getCompany()?->getId()->equals($companyId) ? $role : null;
    }

    public function save(Role $role): void
    {
        $this->entityManager->persist($role);
        $this->entityManager->flush();
    }
}
