<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\UserRepository;
use App\Identity\Domain\ValueObject\AccountSearch;
use App\Identity\Domain\ValueObject\Email;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\SearchText;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineUserRepository implements UserRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofId(Uuid $id): ?User
    {
        return $this->entityManager->find(User::class, $id);
    }

    public function lockedOfId(Uuid $id): ?User
    {
        $user = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            // SELECT … FOR UPDATE, which Doctrine refuses outside a transaction; the refresh hint replaces what an
            // earlier read of the row in this request left in memory with what the lock just read.
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $user instanceof User ? $user : null;
    }

    public function platformOperators(): array
    {
        /** @var list<User> $operators */
        $operators = $this->entityManager->getRepository(User::class)->findBy(['isPlatformOperator' => true, 'isActive' => true], ['email' => 'ASC']);

        return $operators;
    }

    public function withTotpSecret(): array
    {
        /** @var list<User> $users */
        $users = $this->entityManager->createQueryBuilder()->select('u')->from(User::class, 'u')
            ->where('u.totpSecret IS NOT NULL')->orderBy('u.email')->getQuery()->getResult();

        return $users;
    }

    public function ofEmail(Email $email): ?User
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    public function search(AccountSearch $search, PageRequest $page): Page
    {
        $query = $this->entityManager->createQueryBuilder()->select('u')->from(User::class, 'u');
        $words = TypedText::trimmed($search->text ?? '');
        if ('' !== $words) {
            $query->andWhere("SEARCH_TEXT(u.email, u.displayName) LIKE CONCAT('%', SEARCH_TEXT(:text), '%')")->setParameter('text', SearchText::escapeLike($words));
        }
        if (null !== $search->active) {
            $query->andWhere('u.isActive = :active')->setParameter('active', $search->active);
        }
        if (null !== $search->platformOperator) {
            $query->andWhere('u.isPlatformOperator = :operator')->setParameter('operator', $search->platformOperator);
        }
        if ([] !== $search->leftOut) {
            $query->andWhere('u.id NOT IN (:leftOut)')->setParameter('leftOut', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $search->leftOut), ArrayParameterType::STRING);
        }
        ListOrder::apply($query, $search->order, ['active' => 'u.isActive', 'platformOperator' => 'u.isPlatformOperator', 'createdAt' => 'u.createdAt', 'displayName' => 'u.displayName', 'email' => 'u.email'], [], 'u.email')
            ->setFirstResult($page->offset())->setMaxResults($page->size);

        $paginator = new Paginator($query, fetchJoinCollection: false)->setUseOutputWalkers(false);
        /** @var list<User> $users */
        $users = iterator_to_array($paginator, false);

        return new Page($users, \count($paginator), $page);
    }

    public function deletionsRequestedBefore(\DateTimeImmutable $moment): array
    {
        /** @var list<User> $users */
        $users = $this->entityManager->createQueryBuilder()->select('u')->from(User::class, 'u')
            ->where('u.deletionRequestedAt < :moment')->setParameter('moment', $moment, Types::DATETIME_IMMUTABLE)
            ->orderBy('u.deletionRequestedAt')->getQuery()->getResult();

        return $users;
    }

    public function remove(User $user): void
    {
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    public function save(User $user): void
    {
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}
