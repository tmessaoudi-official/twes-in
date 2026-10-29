<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Doctrine;

use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNoteLine;
use App\Module\DeliveryNotes\Domain\Repository\DeliveryNoteRepository;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteSearch;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteStatus;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Shared\Infrastructure\Doctrine\Intervals;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Shared\Infrastructure\Doctrine\SearchText;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineDeliveryNoteRepository implements DeliveryNoteRepository
{
    /** The condition the delivery note trigram index answers; `SearchIndexesTest` proves the pair. */
    public const string MATCHES_WORDS = "SEARCH_TEXT(n.number, n.customerReference, JSON_VALUES(n.customerSnapshot), n.receivedByName) LIKE CONCAT('%', SEARCH_TEXT(:text), '%')";

    private const array SORTED_BY = [
        'number' => 'n.number',
        'customer' => 'CASE WHEN ck.id IS NULL THEN c.name ELSE ck.name END',
        'issueDate' => 'n.issueDate',
        'deliveryDate' => 'n.deliveryDate',
        'status' => 'n.status',
    ];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        return $this->entityManager->getRepository(DeliveryNote::class)->findBy(['company' => $companyId], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function search(Uuid $companyId, DeliveryNoteSearch $search, PageRequest $page): Page
    {
        $query = $this->filtered($companyId, $search)->select('n', 'c');
        // A draft has no number and no issue day, so neither can settle a tie: the newest first, and the id last,
        // which is unique and never empty.
        ListOrder::apply($query, $search->order, self::SORTED_BY, ['number', 'issueDate', 'deliveryDate'], 'n.createdAt', \SortDirection::Descending)
            ->addOrderBy('n.id', \SortDirection::Descending)
            ->setFirstResult($page->offset())->setMaxResults($page->size);

        $paginator = new Paginator($query, fetchJoinCollection: false)->setUseOutputWalkers(false);
        /** @var list<DeliveryNote> $notes */
        $notes = iterator_to_array($paginator, false);
        $this->loadWhatARowShows($notes);

        return new Page($notes, \count($paginator), $page);
    }

    /**
     * A row answers its lines with their taxes, units and products. Read in one statement for the whole page, they cost
     * the same for 1 row or 100, where walking them row by row cost 35 statements for 6 rows (Doctrine, "Improving
     * performance": fetch joins; audit PF-07). The query only fills collections of notes already in memory.
     *
     * @param list<DeliveryNote> $notes
     */
    private function loadWhatARowShows(array $notes): void
    {
        if ([] === $notes) {
            return;
        }
        $this->entityManager
            ->createQuery('SELECT n, l, lt, u, p FROM '.DeliveryNote::class.' n LEFT JOIN n.lines l LEFT JOIN l.taxes lt LEFT JOIN l.unit u LEFT JOIN l.product p WHERE n.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (DeliveryNote $note): string => $note->getId()->toRfc4122(), $notes), ArrayParameterType::STRING)
            ->getResult();
    }

    public function statusCounts(Uuid $companyId, DeliveryNoteSearch $search): array
    {
        // The chips narrow by status themselves, so whatever status the search carried is left aside.
        $statusFree = $search->withoutStatus();
        $counts = array_fill_keys(array_map(static fn (DeliveryNoteStatus $status): string => $status->value, DeliveryNoteStatus::cases()), 0);
        /** @var list<array{status: DeliveryNoteStatus, total: int|string}> $rows the column is mapped to the enum */
        $rows = $this->filtered($companyId, $statusFree)
            ->select('n.status AS status', 'COUNT(n.id) AS total')->groupBy('n.status')
            ->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['total'];
        }

        return ['all' => array_sum($counts), 'statuses' => $counts];
    }

    /** The company's delivery notes narrowed as a search asks, its order and its page left to the caller. */
    private function filtered(Uuid $companyId, DeliveryNoteSearch $search): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->from(DeliveryNote::class, 'n')
            ->join('n.customer', 'c')
            ->leftJoin('c.mergedInto', 'ck')
            ->where('n.company = :company')->setParameter('company', $companyId, 'uuid');
        $words = TypedText::trimmed($search->text ?? '');
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere(self::MATCHES_WORDS)->setParameter('text', SearchText::escapeLike($words));
        } elseif ('' !== $words) {
            $query->andWhere('LOWER(n.number) = LOWER(:number)')->setParameter('number', $words);
        }
        // The values of one filter are OR'd, the filters AND'd.
        if ([] !== $search->statuses) {
            $query->andWhere('n.status IN (:statuses)')
                ->setParameter('statuses', array_map(static fn (DeliveryNoteStatus $each): string => $each->value, $search->statuses), ArrayParameterType::STRING);
        }
        if ([] !== $search->customers) {
            // A customer's documents are also those made out to a customer merged into it.
            $query->andWhere('(n.customer IN (:customerIds) OR ck.id IN (:customerIds))')
                ->setParameter('customerIds', array_map(static fn (Uuid $each): string => $each->toRfc4122(), $search->customers), ArrayParameterType::STRING);
        }
        Intervals::days($query, 'n.issueDate', 'issued', $search->issueDate);
        Intervals::moments($query, 'n.deliveryDate', 'delivery', $search->deliveryDate, $search->timezone);

        return $query;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?DeliveryNote
    {
        $note = $this->entityManager->find(DeliveryNote::class, $id);

        return null !== $note && $note->getCompany()->getId()->equals($companyId) ? $note : null;
    }

    public function ofIdsInCompany(array $ids, Uuid $companyId): array
    {
        if ([] === $ids) {
            return [];
        }
        $notes = $this->entityManager->createQueryBuilder()
            ->select('n')
            ->from(DeliveryNote::class, 'n')
            ->where('n.id IN (:ids)')
            ->andWhere('n.company = :company')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids), ArrayParameterType::STRING)
            ->setParameter('company', $companyId, 'uuid')
            ->getQuery()
            ->getResult();

        return Rows::entities($notes, DeliveryNote::class);
    }

    public function lockedOfIdsInCompany(array $ids, Uuid $companyId): array
    {
        return $this->locked('n.id IN (:ids)', $ids, $companyId);
    }

    public function lockedOfLineIdsInCompany(array $lineIds, Uuid $companyId): array
    {
        return $this->locked('n.id IN (SELECT IDENTITY(l.deliveryNote) FROM '.DeliveryNoteLine::class.' l WHERE l.id IN (:ids))', $lineIds, $companyId);
    }

    public function quoteLineQuantities(Uuid $companyId, array $quoteLineIds, ?Uuid $except = null, bool $deliveredOnly = false): array
    {
        return $this->sourceLineQuantities('sourceQuoteLineId', $companyId, $quoteLineIds, $except, $deliveredOnly);
    }

    public function invoiceLineQuantities(Uuid $companyId, array $invoiceLineIds, ?Uuid $except = null, bool $deliveredOnly = false): array
    {
        return $this->sourceLineQuantities('sourceInvoiceLineId', $companyId, $invoiceLineIds, $except, $deliveredOnly);
    }

    /**
     * @param 'sourceQuoteLineId'|'sourceInvoiceLineId' $field         the line of the other document a note line names
     * @param list<Uuid>                                $sourceLineIds
     *
     * @return array<string, string>
     */
    private function sourceLineQuantities(string $field, Uuid $companyId, array $sourceLineIds, ?Uuid $except, bool $deliveredOnly): array
    {
        if ([] === $sourceLineIds) {
            return [];
        }
        $excluded = $deliveredOnly ? [DeliveryNoteStatus::Cancelled->value, DeliveryNoteStatus::Draft->value] : [DeliveryNoteStatus::Cancelled->value];
        $query = $this->entityManager->createQueryBuilder()
            ->select('l.'.$field.' AS line', 'SUM(l.quantity) AS quantity')
            ->from(DeliveryNoteLine::class, 'l')
            ->join('l.deliveryNote', 'n')
            ->where('n.company = :company')
            ->andWhere('n.status NOT IN (:excluded)')
            ->andWhere('l.'.$field.' IN (:lines)')
            ->groupBy('l.'.$field)
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('excluded', $excluded, ArrayParameterType::STRING)
            ->setParameter('lines', self::ids($sourceLineIds), ArrayParameterType::STRING);
        if (null !== $except) {
            $query->andWhere('n.id <> :except')->setParameter('except', $except, 'uuid');
        }
        $quantities = [];
        foreach (Rows::row($query->getQuery()->getArrayResult()) as $row) {
            $row = Rows::row($row);
            $line = $row['line'] ?? null;
            if (!$line instanceof Uuid) {
                throw new \UnexpectedValueException('A source line the query groups by came back empty.');
            }
            $quantities[$line->toRfc4122()] = Rows::sum($row['quantity'] ?? null);
        }

        return $quantities;
    }

    public function delivering(Uuid $companyId, array $quoteLineIds): array
    {
        return $this->notesNaming('sourceQuoteLineId', $companyId, $quoteLineIds);
    }

    public function deliveringInvoiceLines(Uuid $companyId, array $invoiceLineIds): array
    {
        return $this->notesNaming('sourceInvoiceLineId', $companyId, $invoiceLineIds);
    }

    /**
     * @param 'sourceQuoteLineId'|'sourceInvoiceLineId' $field
     * @param list<Uuid>                                $sourceLineIds
     *
     * @return list<DeliveryNote>
     */
    private function notesNaming(string $field, Uuid $companyId, array $sourceLineIds): array
    {
        if ([] === $sourceLineIds) {
            return [];
        }
        $notes = $this->entityManager->createQueryBuilder()
            ->select('n')
            ->from(DeliveryNote::class, 'n')
            ->where('n.id IN (SELECT IDENTITY(l.deliveryNote) FROM '.DeliveryNoteLine::class.' l WHERE l.'.$field.' IN (:lines))')
            ->andWhere('n.company = :company')
            ->andWhere('n.status <> :cancelled')
            ->orderBy('n.createdAt')
            ->addOrderBy('n.id')
            ->setParameter('lines', self::ids($sourceLineIds), ArrayParameterType::STRING)
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('cancelled', DeliveryNoteStatus::Cancelled->value)
            ->getQuery()
            ->getResult();

        return Rows::entities($notes, DeliveryNote::class);
    }

    public function linesDelivering(Uuid $companyId, array $quoteLineIds): array
    {
        if ([] === $quoteLineIds) {
            return [];
        }
        $rows = $this->entityManager->createQueryBuilder()
            ->select('l.id AS id', 'l.sourceQuoteLineId AS line')
            ->from(DeliveryNoteLine::class, 'l')
            ->where('l.company = :company')
            ->andWhere('l.sourceQuoteLineId IN (:lines)')
            ->orderBy('l.id')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('lines', self::ids($quoteLineIds), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();
        $lines = [];
        foreach (Rows::row($rows) as $row) {
            $row = Rows::row($row);
            [$id, $line] = [$row['id'] ?? null, $row['line'] ?? null];
            if (!$id instanceof Uuid || !$line instanceof Uuid) {
                throw new \UnexpectedValueException('A delivery note line the query names came back empty.');
            }
            $lines[$line->toRfc4122()][] = $id;
        }

        return $lines;
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids);
    }

    public function numberTaken(Uuid $companyId, string $number): bool
    {
        return null !== $this->entityManager->getRepository(DeliveryNote::class)->findOneBy(['company' => $companyId, 'number' => $number]);
    }

    public function save(DeliveryNote $note): void
    {
        $this->entityManager->persist($note);
        $this->entityManager->flush();
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<DeliveryNote>
     */
    private function locked(string $condition, array $ids, Uuid $companyId): array
    {
        if ([] === $ids) {
            return [];
        }
        $notes = $this->entityManager->createQueryBuilder()
            ->select('n')
            ->from(DeliveryNote::class, 'n')
            ->where($condition)
            ->andWhere('n.company = :company')
            ->orderBy('n.id')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids), ArrayParameterType::STRING)
            ->setParameter('company', $companyId, 'uuid')
            ->getQuery()
            // SELECT … FOR UPDATE in id order, so two requests holding the same notes wait for each other rather than deadlock.
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return Rows::entities($notes, DeliveryNote::class);
    }
}
