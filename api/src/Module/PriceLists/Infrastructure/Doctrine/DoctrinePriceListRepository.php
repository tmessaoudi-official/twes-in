<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\PriceLists\Infrastructure\Doctrine;

use App\Module\PriceLists\Domain\PriceList;
use App\Module\PriceLists\Domain\PriceListRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrinePriceListRepository implements PriceListRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        return $this->entityManager->getRepository(PriceList::class)->findBy(['company' => $companyId], ['name' => 'ASC']);
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?PriceList
    {
        $list = $this->entityManager->find(PriceList::class, $id);

        return null !== $list && $list->getCompany()->getId()->equals($companyId) ? $list : null;
    }

    public function ofNameInCompany(string $name, Uuid $companyId): ?PriceList
    {
        return $this->entityManager->getRepository(PriceList::class)->findOneBy(['company' => $companyId, 'name' => $name]);
    }

    public function applicable(Uuid $companyId, \DateTimeImmutable $on, ?Uuid $customerId, ?Uuid $customerGroupId): array
    {
        $day = $on->setTime(0, 0);
        $query = $this->entityManager->createQueryBuilder()
            ->select('l')
            ->from(PriceList::class, 'l')
            ->where('l.company = :company')
            ->andWhere('l.isActive = true')
            ->andWhere('l.validFrom IS NULL OR l.validFrom <= :day')
            ->andWhere('l.validTo IS NULL OR l.validTo >= :day')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('day', $day, 'date_immutable');
        $scopes = ['(l.customerId IS NULL AND l.customerGroupId IS NULL)'];
        if (null !== $customerId) {
            $scopes[] = 'l.customerId = :customer';
            $query->setParameter('customer', $customerId, 'uuid');
        }
        if (null !== $customerGroupId) {
            $scopes[] = 'l.customerGroupId = :group';
            $query->setParameter('group', $customerGroupId, 'uuid');
        }
        $query->andWhere(implode(' OR ', $scopes));

        /** @var list<PriceList> $found */
        $found = $query->getQuery()->getResult();

        return $found;
    }

    public function save(PriceList $list): void
    {
        $this->entityManager->persist($list);
        $this->entityManager->flush();
    }

    public function remove(PriceList $list): void
    {
        $this->entityManager->remove($list);
        $this->entityManager->flush();
    }
}
