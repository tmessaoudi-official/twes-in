<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Doctrine;

use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerRepository;
use App\Module\Customers\Domain\CustomerSearch;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\SearchText;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineCustomerRepository implements CustomerRepository
{
    /** The words of `:text`, found through idx_customer_search: the index is built on this very SEARCH_TEXT expression. */
    public const string MATCHES_WORDS = "SEARCH_TEXT(c.number, c.name, c.legalName, c.email, c.billingAddress.line1, c.billingAddress.postalCode, c.billingAddress.city, JSON_VALUES(c.identifiers)) LIKE CONCAT('%', SEARCH_TEXT(:text), '%')";
    private const array SORTED_BY = ['number' => 'c.number', 'name' => 'c.name', 'kind' => 'c.kind', 'customerGroup' => 'g.name', 'city' => 'c.billingAddress.city', 'isActive' => 'c.isActive'];

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

    public function search(Uuid $companyId, CustomerSearch $search, PageRequest $page): Page
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('c', 'g', 'r')->from(Customer::class, 'c')
            ->leftJoin('c.group', 'g')->join('c.taxRegime', 'r')
            ->where('c.company = :company')->setParameter('company', $companyId, 'uuid');
        $words = trim($search->text ?? '');
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere(self::MATCHES_WORDS)->setParameter('text', SearchText::escapeLike($words));
        } elseif ('' !== $words) {
            $query->andWhere('LOWER(c.number) = LOWER(:number)')->setParameter('number', $words);
        }
        if (null !== $search->kind) {
            $query->andWhere('c.kind = :kind')->setParameter('kind', $search->kind->value);
        }
        if (null !== $search->groupId) {
            $query->andWhere('c.group = :group')->setParameter('group', $search->groupId, 'uuid');
        }
        if (null !== $search->active) {
            $query->andWhere('c.isActive = :active')->setParameter('active', $search->active);
        }
        ListOrder::apply($query, $search->order, self::SORTED_BY, ['customerGroup', 'city'], 'c.number')
            ->setFirstResult($page->offset())->setMaxResults($page->size);

        $paginator = new Paginator($query, fetchJoinCollection: false);
        /** @var list<Customer> $customers */
        $customers = iterator_to_array($paginator, false);

        return new Page($customers, \count($paginator), $page);
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
