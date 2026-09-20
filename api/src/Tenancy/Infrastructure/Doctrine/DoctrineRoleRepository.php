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

    public function forCompany(Uuid $companyId): array
    {
        /** @var list<Role> $roles */
        $roles = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(Role::class, 'r')
            ->where('r.company IS NULL OR r.company = :company')
            ->setParameter('company', $companyId, 'uuid')
            // The built-in three come first and in rank order, which is neither alphabetical nor insertion order;
            // ordering in SQL rather than in PHP keeps the one answer every reader of this list wants.
            ->orderBy('CASE WHEN r.company IS NULL THEN 0 ELSE 1 END', 'ASC')
            ->addOrderBy(\sprintf("CASE r.name WHEN '%s' THEN 0 WHEN '%s' THEN 1 WHEN '%s' THEN 2 ELSE 3 END", Role::OWNER, Role::ADMIN, Role::MEMBER), 'ASC')
            ->addOrderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $roles;
    }

    public function ofNameForCompany(string $name, Uuid $companyId): ?Role
    {
        foreach ($this->forCompany($companyId) as $role) {
            if ($role->getName() === $name) {
                return $role;
            }
        }

        return null;
    }

    public function nameIsTaken(Uuid $companyId, string $name, ?Uuid $except = null): bool
    {
        foreach ($this->forCompany($companyId) as $role) {
            if ($role->getName() === $name && !(null !== $except && $role->getId()->equals($except))) {
                return true;
            }
        }

        return false;
    }

    public function save(Role $role): void
    {
        $this->entityManager->persist($role);
        $this->entityManager->flush();
    }

    public function remove(Role $role): void
    {
        $this->entityManager->remove($role);
        $this->entityManager->flush();
    }
}
