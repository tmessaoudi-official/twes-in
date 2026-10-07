<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\Doctrine;

use App\Module\Expenses\Domain\Expense;
use App\Module\Expenses\Domain\ExpenseRepository;
use App\Module\Expenses\Domain\ExpenseSearch;
use App\Module\Expenses\Domain\ExpenseStatus;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Shared\Domain\PaymentMethod;
use App\Shared\Infrastructure\Doctrine\Intervals;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\SearchText;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineExpenseRepository implements ExpenseRepository
{
    /** The condition the expense trigram index answers; `SearchIndexesTest` proves the pair. */
    public const string MATCHES_WORDS = "SEARCH_TEXT(e.description, e.reference) LIKE CONCAT('%', SEARCH_TEXT(:text), '%')";

    private const array SORTED_BY = [
        'date' => 'e.date',
        'description' => 'e.description',
        // Who was paid, as the list's column reads it: the vendor, else the payee written on the expense.
        'vendor' => ['CASE WHEN v.id IS NULL THEN e.payee ELSE v.name END', 'COALESCE(v.name, e.payee) IS NULL'],
        'category' => 'k.name',
        'amountGross' => 'e.amountGross',
        'status' => 'e.status',
    ];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        /** @var list<Expense> $expenses */
        $expenses = $this->entityManager->getRepository(Expense::class)->findBy(['company' => $companyId], ['date' => 'DESC', 'createdAt' => 'DESC']);

        return $expenses;
    }

    public function paidBetween(Uuid $companyId, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        /** @var list<Expense> $expenses */
        $expenses = $this->entityManager->createQueryBuilder()
            ->select('e', 'v')->from(Expense::class, 'e')
            ->leftJoin('e.vendor', 'v')
            ->where('e.company = :company')->setParameter('company', $companyId, 'uuid')
            ->andWhere('e.status = :paid')->setParameter('paid', ExpenseStatus::Paid)
            ->andWhere('e.paidOn >= :from')->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->andWhere('e.paidOn < :until')->setParameter('until', $until, Types::DATE_IMMUTABLE)
            ->orderBy('e.paidOn', 'ASC')->addOrderBy('e.createdAt', 'ASC')->addOrderBy('e.id', 'ASC')
            ->getQuery()->getResult();

        return $expenses;
    }

    public function recordedGrossBetween(Uuid $companyId, \DateTimeImmutable $from, \DateTimeImmutable $until): string
    {
        $sum = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(e.amountGross), 0)')->from(Expense::class, 'e')
            ->where('e.company = :company')->setParameter('company', $companyId, 'uuid')
            ->andWhere('e.status IN (:recorded)')->setParameter('recorded', [ExpenseStatus::Recorded, ExpenseStatus::Paid])
            ->andWhere('e.date >= :from')->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->andWhere('e.date < :until')->setParameter('until', $until, Types::DATE_IMMUTABLE)
            ->getQuery()->getSingleScalarResult();
        \assert(is_numeric($sum));

        return (string) $sum;
    }

    public function search(Uuid $companyId, ExpenseSearch $search, PageRequest $page): Page
    {
        $query = $this->filtered($companyId, $search)->select('e', 'v', 'k');
        // Asked for nothing, the list reads the latest day first, as it always has; the day is a sort key of its own,
        // so it is the default rather than the tie-break, which would name the same column twice. An expense carries
        // no number, so what settles a tie is when it was filed, then its id, which is unique and never empty.
        $order = [] === $search->order ? ['date' => 'desc'] : $search->order;
        ListOrder::apply($query, $order, self::SORTED_BY, ['vendor', 'category'], 'e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult($page->offset())->setMaxResults($page->size);

        $paginator = new Paginator($query, fetchJoinCollection: false)->setUseOutputWalkers(false);
        /** @var list<Expense> $expenses */
        $expenses = iterator_to_array($paginator, false);

        return new Page($expenses, \count($paginator), $page);
    }

    public function statusCounts(Uuid $companyId, ExpenseSearch $search): array
    {
        // The chips narrow by status themselves, so whatever status the search carried is left aside.
        $statusFree = $search->withoutStatus();
        $counts = array_fill_keys(array_map(static fn (ExpenseStatus $status): string => $status->value, ExpenseStatus::cases()), 0);
        /** @var list<array{status: ExpenseStatus, total: int|string}> $rows the column is mapped to the enum */
        $rows = $this->filtered($companyId, $statusFree)
            ->select('e.status AS status', 'COUNT(e.id) AS total')->groupBy('e.status')
            ->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['total'];
        }

        return ['all' => array_sum($counts), 'statuses' => $counts];
    }

    /** The company's expenses narrowed as a search asks, its order and its page left to the caller. */
    private function filtered(Uuid $companyId, ExpenseSearch $search): QueryBuilder
    {
        // The vendor and the category are optional, so a left join: narrowing by one is what excludes the rows
        // without it, never the join itself.
        $query = $this->entityManager->createQueryBuilder()
            ->from(Expense::class, 'e')
            ->leftJoin('e.vendor', 'v')->leftJoin('e.category', 'k')
            ->where('e.company = :company')->setParameter('company', $companyId, 'uuid');
        $words = trim($search->text ?? '');
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere(self::MATCHES_WORDS)->setParameter('text', SearchText::escapeLike($words));
        } elseif ('' !== $words) {
            $query->andWhere('LOWER(e.reference) = LOWER(:reference)')->setParameter('reference', $words);
        }
        if ([] !== $search->statuses) {
            $query->andWhere('e.status IN (:statuses)')
                ->setParameter('statuses', array_map(static fn (ExpenseStatus $each): string => $each->value, $search->statuses), ArrayParameterType::STRING);
        }
        if ([] !== $search->vendors) {
            $query->andWhere('e.vendor IN (:vendorIds)')->setParameter('vendorIds', self::ids($search->vendors), ArrayParameterType::STRING);
        }
        if ([] !== $search->categories) {
            $query->andWhere('e.category IN (:categoryIds)')->setParameter('categoryIds', self::ids($search->categories), ArrayParameterType::STRING);
        }
        if ([] !== $search->paymentMethods) {
            $query->andWhere('e.paymentMethod IN (:paymentMethods)')
                ->setParameter('paymentMethods', array_map(static fn (PaymentMethod $each): string => $each->value, $search->paymentMethods), ArrayParameterType::STRING);
        }
        if (null !== $search->withheld) {
            $query->andWhere($search->withheld ? 'e.withholdingAmount IS NOT NULL' : 'e.withholdingAmount IS NULL');
        }
        Intervals::days($query, 'e.date', 'date', $search->date);
        Intervals::amounts($query, 'e.amountGross', 'gross', $search->amountGross);

        return $query;
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_map(static fn (Uuid $each): string => $each->toRfc4122(), $ids);
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Expense
    {
        $expense = $this->entityManager->find(Expense::class, $id);

        return null !== $expense && $expense->getCompany()->getId()->equals($companyId) ? $expense : null;
    }

    public function save(Expense $expense): void
    {
        $this->entityManager->persist($expense);
        $this->entityManager->flush();
    }

    public function remove(Expense $expense): void
    {
        $this->entityManager->remove($expense);
        $this->entityManager->flush();
    }
}
