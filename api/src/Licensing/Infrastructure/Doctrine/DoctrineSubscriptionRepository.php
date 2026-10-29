<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Infrastructure\Doctrine;

use App\Licensing\Domain\Model\Subscription;
use App\Licensing\Domain\Repository\SubscriptionRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
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

    public function lockedOfCompany(Uuid $companyId): ?Subscription
    {
        // SELECT … FOR UPDATE; the refresh hint replaces what an earlier read of the row in this request left in memory.
        $subscription = $this->entityManager->createQueryBuilder()
            ->select('s')->from(Subscription::class, 's')
            ->where('s.company = :company')->setParameter('company', $companyId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $subscription instanceof Subscription ? $subscription : null;
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

    public function coveredBy(\DateTimeImmutable $by): array
    {
        // Covered time ends at the later of the trial and the paid period, so it ends by then when both do.
        /** @var list<Subscription> $subscriptions */
        $subscriptions = $this->entityManager->createQueryBuilder()
            ->select('s', 'c')->from(Subscription::class, 's')->join('s.company', 'c')
            ->where('s.trialEndsAt IS NULL OR s.trialEndsAt <= :by')
            ->andWhere('s.paidUntil IS NULL OR s.paidUntil <= :by')
            ->orderBy('s.id', \SortDirection::Ascending)
            ->setParameter('by', $by, Types::DATETIMETZ_IMMUTABLE)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $subscriptions;
    }

    public function lapseTold(Subscription $subscription, \DateTimeImmutable $coveredUntil): void
    {
        $this->entityManager->createQuery('UPDATE '.Subscription::class.' s SET s.lapseToldFor = :covered WHERE s.id = :id')
            ->setParameter('covered', $coveredUntil, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('id', $subscription->getId(), 'uuid')
            ->execute();
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
