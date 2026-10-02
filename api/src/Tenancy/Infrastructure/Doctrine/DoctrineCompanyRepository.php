<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Doctrine;

use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\SearchText;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyRepository;
use App\Tenancy\Domain\CompanySearch;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Role;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineCompanyRepository implements CompanyRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofId(Uuid $id): ?Company
    {
        return $this->entityManager->getRepository(Company::class)->find($id);
    }

    public function ofName(string $name): ?Company
    {
        return $this->entityManager->getRepository(Company::class)->findOneBy(['name' => $name]);
    }

    public function all(): array
    {
        return $this->entityManager->getRepository(Company::class)->findBy([], ['name' => 'ASC']);
    }

    public function search(CompanySearch $search, PageRequest $page): Page
    {
        $query = $this->entityManager->createQueryBuilder()->select('c')->from(Company::class, 'c');
        $words = trim($search->text ?? '');
        if ('' !== $words) {
            $owners = $this->entityManager->createQueryBuilder()->select('1')->from(Membership::class, 'm')
                ->join('m.user', 'u')->join('m.role', 'r')
                ->where('m.company = c')->andWhere('r.name = :owner')
                ->andWhere("LOWER(u.email) LIKE CONCAT('%', LOWER(:text), '%')")->getDQL();
            $query->andWhere("SEARCH_TEXT(c.name) LIKE CONCAT('%', SEARCH_TEXT(:text), '%') OR EXISTS ($owners)")
                ->setParameter('text', SearchText::escapeLike($words))->setParameter('owner', Role::OWNER);
        }
        if (null !== $search->status) {
            $query->andWhere('c.status = :status')->setParameter('status', $search->status);
        }
        if (null !== $search->countryCode) {
            $query->andWhere('c.countryCode = :country')->setParameter('country', $search->countryCode);
        }
        ListOrder::apply($query, $search->order, ['name' => 'c.name', 'countryCode' => 'c.countryCode', 'status' => 'c.status', 'createdAt' => 'c.createdAt'], [], 'c.name')
            ->setFirstResult($page->offset())->setMaxResults($page->size);

        $paginator = new Paginator($query, fetchJoinCollection: false)->setUseOutputWalkers(false);
        /** @var list<Company> $companies */
        $companies = iterator_to_array($paginator, false);

        return new Page($companies, \count($paginator), $page);
    }

    public function save(Company $company): void
    {
        $this->entityManager->persist($company);
        $this->entityManager->flush();
    }
}
