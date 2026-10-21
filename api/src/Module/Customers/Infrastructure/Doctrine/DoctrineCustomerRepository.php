<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Doctrine;

use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\Repository\CustomerRepository;
use App\Module\Customers\Domain\ValueObject\CustomerCheckScope;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerSearch;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Shared\Infrastructure\Doctrine\Intervals;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\SearchText;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
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
            ->where('c.company = :company')->setParameter('company', $companyId, 'uuid')
            // A customer merged away is listed as the customer kept, which is listed already.
            ->andWhere('c.mergedInto IS NULL');
        $words = trim($search->text ?? '');
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere(self::MATCHES_WORDS)->setParameter('text', SearchText::escapeLike($words));
        } elseif ('' !== $words) {
            $query->andWhere('LOWER(c.number) = LOWER(:number)')->setParameter('number', $words);
        }
        if ([] !== $search->kinds) {
            $query->andWhere('c.kind IN (:kinds)')
                ->setParameter('kinds', array_map(static fn (CustomerKind $each): string => $each->value, $search->kinds), ArrayParameterType::STRING);
        }
        if ([] !== $search->groups) {
            $query->andWhere('c.group IN (:groupIds)')
                ->setParameter('groupIds', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $search->groups), ArrayParameterType::STRING);
        }
        if ([] !== $search->regimes) {
            $query->andWhere('r.code IN (:regimes)')->setParameter('regimes', $search->regimes, ArrayParameterType::STRING);
        }
        Intervals::moments($query, 'c.createdAt', 'created', $search->createdOn, $search->timezone);
        if (null !== $search->active) {
            $query->andWhere('c.isActive = :active')->setParameter('active', $search->active);
        }
        if (null !== $search->toCheck && null !== $search->checkScope) {
            $toCheck = self::toCheck($query, $search->checkScope);
            $query->andWhere($search->toCheck ? $toCheck : 'NOT '.$toCheck);
        }
        ListOrder::apply($query, $search->order, self::SORTED_BY, ['customerGroup', 'city'], 'c.number')
            ->setFirstResult($page->offset())->setMaxResults($page->size);

        $paginator = new Paginator($query, fetchJoinCollection: false)->setUseOutputWalkers(false);
        /** @var list<Customer> $customers */
        $customers = iterator_to_array($paginator, false);

        return new Page($customers, \count($paginator), $page);
    }

    /**
     * « À vérifier », as `Customer::getCheckMark()` and `checkReasons()` say it of each customer: marked, or a business
     * of the seller's country (or of none named) without a number the scope requires.
     */
    private static function toCheck(QueryBuilder $query, CustomerCheckScope $scope): string
    {
        if ([] === $scope->requiredOfBusiness) {
            return '(c.toCheck = true)';
        }
        $missing = [];
        foreach ($scope->requiredOfBusiness as $index => $key) {
            $missing[] = "JSON_HAS_KEY(c.identifiers, :checkKey$index) = false";
            $query->setParameter("checkKey$index", $key);
        }
        $query->setParameter('checkKind', CustomerKind::Company->value)->setParameter('checkCountry', $scope->countryCode);

        return '(c.toCheck = true OR (c.kind = :checkKind AND (c.billingAddress.countryCode IS NULL OR c.billingAddress.countryCode = :checkCountry) AND ('.implode(' OR ', $missing).')))';
    }

    public function ofIdsInCompany(array $ids, Uuid $companyId): array
    {
        return [] === $ids ? [] : $this->entityManager->getRepository(Customer::class)->findBy(['id' => $ids, 'company' => $companyId]);
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Customer
    {
        $customer = $this->entityManager->find(Customer::class, $id);

        return null !== $customer && $customer->getCompany()->getId()->equals($companyId) ? $customer : null;
    }

    public function pick(Uuid $companyId, string $words, int $limit): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('c', 'r')->from(Customer::class, 'c')
            ->join('c.taxRegime', 'r')
            ->where('c.company = :company')->setParameter('company', $companyId, 'uuid')
            ->andWhere('c.isActive = true');
        $words = trim($words);
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere(self::MATCHES_WORDS)->setParameter('text', SearchText::escapeLike($words));
        } elseif ('' !== $words) {
            $query->andWhere('LOWER(c.number) = LOWER(:number)')->setParameter('number', $words);
        }

        /** @var list<Customer> $customers */
        $customers = $query->orderBy('c.number', 'ASC')->setMaxResults($limit)->getQuery()->getResult();

        return $customers;
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
