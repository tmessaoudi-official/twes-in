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
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\SearchText;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineExpenseRepository implements ExpenseRepository
{
    /** The condition the expense trigram index answers; `SearchIndexesTest` proves the pair. */
    public const string MATCHES_WORDS = "SEARCH_TEXT(e.description, e.reference) LIKE CONCAT('%', SEARCH_TEXT(:text), '%')";

    private const array SORTED_BY = [
        'date' => 'e.date',
        'description' => 'e.description',
        'vendor' => 'v.name',
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

    public function search(Uuid $companyId, ExpenseSearch $search, PageRequest $page): Page
    {
        // The vendor and the category are optional, so a left join: narrowing by one is what excludes the rows
        // without it, never the join itself.
        $query = $this->entityManager->createQueryBuilder()
            ->select('e', 'v', 'k')->from(Expense::class, 'e')
            ->leftJoin('e.vendor', 'v')->leftJoin('e.category', 'k')
            ->where('e.company = :company')->setParameter('company', $companyId, 'uuid');
        $words = trim($search->text ?? '');
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere(self::MATCHES_WORDS)->setParameter('text', SearchText::escapeLike($words));
        } elseif ('' !== $words) {
            $query->andWhere('LOWER(e.reference) = LOWER(:reference)')->setParameter('reference', $words);
        }
        if (null !== $search->status) {
            $query->andWhere('e.status = :status')->setParameter('status', $search->status->value);
        }
        if (null !== $search->vendor) {
            $query->andWhere('e.vendor = :vendorId')->setParameter('vendorId', $search->vendor, 'uuid');
        }
        if (null !== $search->category) {
            $query->andWhere('e.category = :categoryId')->setParameter('categoryId', $search->category, 'uuid');
        }
        // Asked for nothing, the list reads the latest day first, as it always has; the day is a sort key of its own,
        // so it is the default rather than the tie-break, which would name the same column twice. An expense carries
        // no number, so what settles a tie is when it was filed, then its id, which is unique and never empty.
        $order = [] === $search->order ? ['date' => 'desc'] : $search->order;
        ListOrder::apply($query, $order, self::SORTED_BY, ['vendor', 'category'], 'e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult($page->offset())->setMaxResults($page->size);

        $paginator = new Paginator($query, fetchJoinCollection: false);
        /** @var list<Expense> $expenses */
        $expenses = iterator_to_array($paginator, false);

        return new Page($expenses, \count($paginator), $page);
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
