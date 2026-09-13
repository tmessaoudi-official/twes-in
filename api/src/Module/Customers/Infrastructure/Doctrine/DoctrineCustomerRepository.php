<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Doctrine;

use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineCustomerRepository implements CustomerRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        /** @var list<Customer> $customers */
        $customers = $this->entityManager->createQueryBuilder()
            ->select('c', 'g', 'r')->from(Customer::class, 'c')
            ->leftJoin('c.group', 'g')->join('c.taxRegime', 'r')
            ->where('c.company = :company')->setParameter('company', $companyId, 'uuid')
            ->orderBy('c.number', 'ASC')
            ->getQuery()
            ->getResult();

        return $customers;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Customer
    {
        $customer = $this->entityManager->find(Customer::class, $id);

        return null !== $customer && $customer->getCompany()->getId()->equals($companyId) ? $customer : null;
    }

    public function ofNumberInCompany(string $number, Uuid $companyId): ?Customer
    {
        return $this->entityManager->getRepository(Customer::class)->findOneBy(['company' => $companyId, 'number' => $number]);
    }

    public function countInGroup(Uuid $groupId): int
    {
        return $this->entityManager->getRepository(Customer::class)->count(['group' => $groupId]);
    }

    public function save(Customer $customer): void
    {
        $this->entityManager->persist($customer);
        $this->entityManager->flush();
    }
}
