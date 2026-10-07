<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\Doctrine;

use App\Module\Quotes\Domain\Quote;
use App\Module\Quotes\Domain\QuoteRepository;
use App\Module\Quotes\Domain\QuoteSearch;
use App\Module\Quotes\Domain\QuoteStatus;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Shared\Infrastructure\Doctrine\Intervals;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\SearchText;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineQuoteRepository implements QuoteRepository
{
    /** The condition the quote trigram index answers; `SearchIndexesTest` proves the pair. */
    public const string MATCHES_WORDS = "SEARCH_TEXT(q.number, q.customerReference, JSON_VALUES(q.customerSnapshot)) LIKE CONCAT('%', SEARCH_TEXT(:text), '%')";

    private const array SORTED_BY = [
        'number' => 'q.number',
        'customer' => 'c.name',
        'issueDate' => 'q.issueDate',
        'validUntil' => 'q.validUntil',
        'status' => 'q.status',
    ];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function search(Uuid $companyId, QuoteSearch $search, PageRequest $page): Page
    {
        $query = $this->filtered($companyId, $search)->select('q', 'c');
        // A draft has no number and no issue day, so neither can settle a tie: the newest first, and the id last,
        // which is unique and never empty.
        ListOrder::apply($query, $search->order, self::SORTED_BY, ['number', 'issueDate', 'validUntil'], 'q.createdAt', 'DESC')
            ->addOrderBy('q.id', 'DESC')
            ->setFirstResult($page->offset())->setMaxResults($page->size);

        $paginator = new Paginator($query, fetchJoinCollection: false)->setUseOutputWalkers(false);
        /** @var list<Quote> $quotes */
        $quotes = iterator_to_array($paginator, false);
        $this->loadWhatARowShows($quotes);

        return new Page($quotes, \count($paginator), $page);
    }

    /**
     * A row answers its lines with their taxes, units and products, read in one statement for the whole page: they
     * cost the same for 1 row or 100. The query only fills collections of quotes already in memory.
     *
     * @param list<Quote> $quotes
     */
    private function loadWhatARowShows(array $quotes): void
    {
        if ([] === $quotes) {
            return;
        }
        $this->entityManager
            ->createQuery('SELECT q, l, lt, u, p FROM '.Quote::class.' q LEFT JOIN q.lines l LEFT JOIN l.taxes lt LEFT JOIN l.unit u LEFT JOIN l.product p WHERE q.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Quote $quote): string => $quote->getId()->toRfc4122(), $quotes), ArrayParameterType::STRING)
            ->getResult();
    }

    public function statusCounts(Uuid $companyId, QuoteSearch $search): array
    {
        // The chips narrow by status themselves, so whatever status the search carried is left aside.
        $counts = array_fill_keys(array_map(static fn (QuoteStatus $status): string => $status->value, QuoteStatus::cases()), 0);
        /** @var list<array{status: QuoteStatus, total: int|string}> $rows the column is mapped to the enum */
        $rows = $this->filtered($companyId, $search->withoutStatus())
            ->select('q.status AS status', 'COUNT(q.id) AS total')->groupBy('q.status')
            ->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['total'];
        }

        return ['all' => array_sum($counts), 'statuses' => $counts];
    }

    /** The company's quotes narrowed as a search asks, its order and its page left to the caller. */
    private function filtered(Uuid $companyId, QuoteSearch $search): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->from(Quote::class, 'q')
            ->join('q.customer', 'c')
            ->where('q.company = :company')->setParameter('company', $companyId, 'uuid');
        $words = trim($search->text ?? '');
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere(self::MATCHES_WORDS)->setParameter('text', SearchText::escapeLike($words));
        } elseif ('' !== $words) {
            $query->andWhere('LOWER(q.number) = LOWER(:number)')->setParameter('number', $words);
        }
        // The values of one filter are OR'd, the filters AND'd.
        if ([] !== $search->statuses) {
            $query->andWhere('q.status IN (:statuses)')
                ->setParameter('statuses', array_map(static fn (QuoteStatus $each): string => $each->value, $search->statuses), ArrayParameterType::STRING);
        }
        if ([] !== $search->customers) {
            $query->andWhere('q.customer IN (:customerIds)')
                ->setParameter('customerIds', array_map(static fn (Uuid $each): string => $each->toRfc4122(), $search->customers), ArrayParameterType::STRING);
        }
        Intervals::days($query, 'q.issueDate', 'issued', $search->issueDate);

        return $query;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Quote
    {
        $quote = $this->entityManager->find(Quote::class, $id);

        return null !== $quote && $quote->getCompany()->getId()->equals($companyId) ? $quote : null;
    }

    public function lockedOfIdInCompany(Uuid $id, Uuid $companyId): ?Quote
    {
        $quote = $this->entityManager->createQueryBuilder()
            ->select('q')
            ->from(Quote::class, 'q')
            ->where('q.id = :id')
            ->andWhere('q.company = :company')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('company', $companyId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $quote instanceof Quote ? $quote : null;
    }

    public function numberTaken(Uuid $companyId, string $number): bool
    {
        return null !== $this->entityManager->getRepository(Quote::class)->findOneBy(['company' => $companyId, 'number' => $number]);
    }

    public function save(Quote $quote): void
    {
        $this->entityManager->persist($quote);
        $this->entityManager->flush();
    }
}
