<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Infrastructure\Doctrine;

use App\Licensing\Domain\Subscription;
use App\Licensing\Domain\SubscriptionRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineSubscriptionRepository implements SubscriptionRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): ?Subscription
    {
        return $this->entityManager->getRepository(Subscription::class)->findOneBy(['company' => $companyId]);
    }

    public function ofCompanies(array $companyIds): array
    {
        if ([] === $companyIds) {
            return [];
        }
        /** @var list<Subscription> $subscriptions */
        $subscriptions = $this->entityManager->createQueryBuilder()
            ->select('s', 'c')->from(Subscription::class, 's')->join('s.company', 'c')
            ->where('s.company IN (:companies)')
            ->setParameter('companies', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $companyIds), ArrayParameterType::STRING)
            ->getQuery()->getResult();

        $byCompany = [];
        foreach ($subscriptions as $subscription) {
            $byCompany[$subscription->getCompany()->getId()->toRfc4122()] = $subscription;
        }

        return $byCompany;
    }

    public function save(Subscription $subscription): void
    {
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
    }

    public function remove(Subscription $subscription): void
    {
        $this->entityManager->remove($subscription);
        $this->entityManager->flush();
    }
}
