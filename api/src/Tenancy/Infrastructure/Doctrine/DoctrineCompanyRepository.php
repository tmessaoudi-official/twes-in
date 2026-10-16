<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Doctrine;

use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\SearchText;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Membership;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\Repository\CompanyRepository;
use App\Tenancy\Domain\ValueObject\CompanySearch;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
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

    public function closedDownKeptUntilBefore(\DateTimeImmutable $day): array
    {
        /** @var list<Company> $companies */
        $companies = $this->entityManager->createQueryBuilder()->select('c')->from(Company::class, 'c')
            ->where('c.status = :closed')->andWhere('c.retainUntil < :day')
            ->setParameter('closed', Company::STATUS_CLOSED)->setParameter('day', $day, Types::DATE_IMMUTABLE)
            ->orderBy('c.retainUntil')->addOrderBy('c.id')->getQuery()->getResult();

        return $companies;
    }

    /**
     * One DELETE, never the ORM's removal: a company's rows reach each other through foreign keys checked at the end of
     * the statement, so only a single statement can take them all; the ORM would delete them one table at a time.
     */
    public function removeWhole(Company $company): void
    {
        $this->entityManager->getConnection()->executeStatement('DELETE FROM company WHERE id = ?', [$company->getId()->toRfc4122()]);
        $this->entityManager->detach($company);
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
        if ([] !== $search->statuses) {
            $query->andWhere('c.status IN (:statuses)')->setParameter('statuses', $search->statuses, ArrayParameterType::STRING);
        }
        if ([] !== $search->countryCodes) {
            $query->andWhere('c.countryCode IN (:countries)')->setParameter('countries', $search->countryCodes, ArrayParameterType::STRING);
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
