<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Doctrine;

use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLine;
use App\Module\DeliveryNotes\Domain\DeliveryNoteRepository;
use App\Module\DeliveryNotes\Domain\DeliveryNoteSearch;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\SearchText;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineDeliveryNoteRepository implements DeliveryNoteRepository
{
    /** The condition the delivery note trigram index answers; `SearchIndexesTest` proves the pair. */
    public const string MATCHES_WORDS = "SEARCH_TEXT(n.number, n.customerReference, JSON_VALUES(n.customerSnapshot)) LIKE CONCAT('%', SEARCH_TEXT(:text), '%')";

    private const array SORTED_BY = [
        'number' => 'n.number',
        'customer' => 'c.name',
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
        $query = $this->entityManager->createQueryBuilder()
            ->select('n', 'c')->from(DeliveryNote::class, 'n')
            ->join('n.customer', 'c')
            ->where('n.company = :company')->setParameter('company', $companyId, 'uuid');
        $words = trim($search->text ?? '');
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere(self::MATCHES_WORDS)->setParameter('text', SearchText::escapeLike($words));
        } elseif ('' !== $words) {
            $query->andWhere('LOWER(n.number) = LOWER(:number)')->setParameter('number', $words);
        }
        if (null !== $search->status) {
            $query->andWhere('n.status = :status')->setParameter('status', $search->status->value);
        }
        if (null !== $search->customer) {
            $query->andWhere('n.customer = :customerId')->setParameter('customerId', $search->customer, 'uuid');
        }
        // A draft has no number and no issue day, so neither can settle a tie: the newest first, and the id last,
        // which is unique and never empty.
        ListOrder::apply($query, $search->order, self::SORTED_BY, ['number', 'issueDate', 'deliveryDate'], 'n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setFirstResult($page->offset())->setMaxResults($page->size);

        $paginator = new Paginator($query, fetchJoinCollection: false);
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

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?DeliveryNote
    {
        $note = $this->entityManager->find(DeliveryNote::class, $id);

        return null !== $note && $note->getCompany()->getId()->equals($companyId) ? $note : null;
    }

    public function lockedOfIdsInCompany(array $ids, Uuid $companyId): array
    {
        return $this->locked('n.id IN (:ids)', $ids, $companyId);
    }

    public function lockedOfLineIdsInCompany(array $lineIds, Uuid $companyId): array
    {
        return $this->locked('n.id IN (SELECT IDENTITY(l.deliveryNote) FROM '.DeliveryNoteLine::class.' l WHERE l.id IN (:ids))', $lineIds, $companyId);
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

        return array_values(array_filter(\is_array($notes) ? $notes : [], static fn (mixed $note): bool => $note instanceof DeliveryNote));
    }
}
