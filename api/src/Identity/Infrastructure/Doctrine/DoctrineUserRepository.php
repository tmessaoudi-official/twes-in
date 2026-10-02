<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\AccountSearch;
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\SearchText;
use Doctrine\ORM\EntityManagerInterface;
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

    public function platformOperators(): array
    {
        /** @var list<User> $operators */
        $operators = $this->entityManager->getRepository(User::class)->findBy(['isPlatformOperator' => true, 'isActive' => true], ['email' => 'ASC']);

        return $operators;
    }

    public function ofEmail(Email $email): ?User
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    public function search(AccountSearch $search, PageRequest $page): Page
    {
        $query = $this->entityManager->createQueryBuilder()->select('u')->from(User::class, 'u');
        $words = trim($search->text ?? '');
        if ('' !== $words) {
            $query->andWhere("SEARCH_TEXT(u.email, u.displayName) LIKE CONCAT('%', SEARCH_TEXT(:text), '%')")->setParameter('text', SearchText::escapeLike($words));
        }
        if (null !== $search->active) {
            $query->andWhere('u.isActive = :active')->setParameter('active', $search->active);
        }
        if (null !== $search->platformOperator) {
            $query->andWhere('u.isPlatformOperator = :operator')->setParameter('operator', $search->platformOperator);
        }
        ListOrder::apply($query, $search->order, ['active' => 'u.isActive', 'platformOperator' => 'u.isPlatformOperator', 'createdAt' => 'u.createdAt', 'displayName' => 'u.displayName', 'email' => 'u.email'], [], 'u.email')
            ->setFirstResult($page->offset())->setMaxResults($page->size);

        $paginator = new Paginator($query, fetchJoinCollection: false)->setUseOutputWalkers(false);
        /** @var list<User> $users */
        $users = iterator_to_array($paginator, false);

        return new Page($users, \count($paginator), $page);
    }

    public function save(User $user): void
    {
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}
