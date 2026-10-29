<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\Doctrine;

use App\Module\Purchases\Domain\Model\PurchaseOrder;
use App\Module\Purchases\Domain\Repository\PurchaseOrderRepository;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderSearch;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderStatus;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
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

final readonly class DoctrinePurchaseOrderRepository implements PurchaseOrderRepository
{
    /**
     * The condition the purchase order trigram index answers, an order found by its number, its vendor or who received
     * its goods; `SearchIndexesTest` proves the pair.
     */
    public const string MATCHES_WORDS = "SEARCH_TEXT(o.number, o.vendorName, o.receivers) LIKE CONCAT('%', SEARCH_TEXT(:text), '%')";

    private const array SORTED_BY = [
        'number' => 'o.number',
        'vendor' => 'o.vendorName',
        'issueDate' => 'o.issueDate',
        'expectedOn' => 'o.expectedOn',
        'status' => 'o.status',
    ];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function search(Uuid $companyId, PurchaseOrderSearch $search, PageRequest $page): Page
    {
        $query = $this->filtered($companyId, $search)->select('o');
        // A draft has no number and no issue day, so neither can settle a tie: the newest first, and the id last.
        ListOrder::apply($query, $search->order, self::SORTED_BY, ['number', 'issueDate', 'expectedOn'], 'o.createdAt', \SortDirection::Descending)
            ->addOrderBy('o.id', \SortDirection::Descending)
            ->setFirstResult($page->offset())->setMaxResults($page->size);

        $paginator = new Paginator($query, fetchJoinCollection: false)->setUseOutputWalkers(false);
        /** @var list<PurchaseOrder> $orders */
        $orders = iterator_to_array($paginator, false);
        $this->loadWhatARowShows($orders);

        return new Page($orders, \count($paginator), $page);
    }

    /**
     * A row answers its lines with their units, read in one statement for the whole page rather than row by row.
     *
     * @param list<PurchaseOrder> $orders
     */
    private function loadWhatARowShows(array $orders): void
    {
        if ([] === $orders) {
            return;
        }
        $this->entityManager
            ->createQuery('SELECT o, l, u FROM '.PurchaseOrder::class.' o LEFT JOIN o.lines l LEFT JOIN l.unit u WHERE o.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (PurchaseOrder $order): string => $order->getId()->toRfc4122(), $orders), ArrayParameterType::STRING)
            ->getResult();
    }

    public function statusCounts(Uuid $companyId, PurchaseOrderSearch $search): array
    {
        // The chips narrow by status themselves, so whatever status the search carried is left aside.
        $counts = array_fill_keys(array_map(static fn (PurchaseOrderStatus $status): string => $status->value, PurchaseOrderStatus::cases()), 0);
        /** @var list<array{status: PurchaseOrderStatus, total: int|string}> $rows the column is mapped to the enum */
        $rows = $this->filtered($companyId, $search->withoutStatus())
            ->select('o.status AS status', 'COUNT(o.id) AS total')->groupBy('o.status')
            ->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['total'];
        }

        return ['all' => array_sum($counts), 'statuses' => $counts];
    }

    /** The company's orders narrowed as a search asks, its order and its page left to the caller. */
    private function filtered(Uuid $companyId, PurchaseOrderSearch $search): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->from(PurchaseOrder::class, 'o')
            ->where('o.company = :company')->setParameter('company', $companyId, 'uuid');
        $words = TypedText::trimmed($search->text ?? '');
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere(self::MATCHES_WORDS)->setParameter('text', SearchText::escapeLike($words));
        } elseif ('' !== $words) {
            $query->andWhere('LOWER(o.number) = LOWER(:number)')->setParameter('number', $words);
        }
        // The values of one filter are OR'd, the filters AND'd.
        if ([] !== $search->statuses) {
            $query->andWhere('o.status IN (:statuses)')
                ->setParameter('statuses', array_map(static fn (PurchaseOrderStatus $each): string => $each->value, $search->statuses), ArrayParameterType::STRING);
        }
        if ([] !== $search->vendors) {
            $query->andWhere('o.vendorId IN (:vendorIds)')
                ->setParameter('vendorIds', array_map(static fn (Uuid $each): string => $each->toRfc4122(), $search->vendors), ArrayParameterType::STRING);
        }
        Intervals::days($query, 'o.issueDate', 'issued', $search->issueDate);
        Intervals::days($query, 'o.expectedOn', 'expected', $search->expectedOn);

        return $query;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?PurchaseOrder
    {
        $order = $this->entityManager->find(PurchaseOrder::class, $id);

        return null !== $order && $order->getCompany()->getId()->equals($companyId) ? $order : null;
    }

    public function lockedOfIdInCompany(Uuid $id, Uuid $companyId): ?PurchaseOrder
    {
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(PurchaseOrder::class, 'o')
            ->where('o.id = :id')
            ->andWhere('o.company = :company')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('company', $companyId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $order instanceof PurchaseOrder ? $order : null;
    }

    public function numberTaken(Uuid $companyId, string $number): bool
    {
        return null !== $this->entityManager->getRepository(PurchaseOrder::class)->findOneBy(['company' => $companyId, 'number' => $number]);
    }

    public function save(PurchaseOrder $order): void
    {
        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }
}
