<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Doctrine;

use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceLine;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceSearch;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceType;
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

final readonly class DoctrineInvoiceRepository implements InvoiceRepository
{
    /**
     * The words of `:text`, found through idx_invoice_search: the index is built on this very SEARCH_TEXT expression.
     * All of it is the invoice's own, because an index cannot span a join — and a condition OR-ed onto the customer
     * table would leave this half indexed and scan the other, which no assertion here would notice.
     */
    public const string MATCHES_WORDS = "SEARCH_TEXT(i.number, i.customerReference, JSON_VALUES(i.customerSnapshot)) LIKE CONCAT('%', SEARCH_TEXT(:text), '%')";
    private const string OVERDUE = 'overdue';
    private const array SORTED_BY = ['number' => 'i.number', 'customer' => 'c.name', 'issueDate' => 'i.issueDate', 'dueDate' => 'i.dueDate', 'status' => 'i.status'];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        return $this->entityManager->getRepository(Invoice::class)->findBy(['company' => $companyId], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function search(Uuid $companyId, InvoiceSearch $search, PageRequest $page): Page
    {
        $query = $this->filtered($companyId, $search)->select('i', 'c');
        // A draft has no number, so the number cannot settle a tie the way a product's reference does: the newest
        // first, and the id last, which is unique and never null.
        ListOrder::apply($query, $search->order, self::SORTED_BY, ['number', 'issueDate', 'dueDate'], 'i.createdAt', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setFirstResult($page->offset())->setMaxResults($page->size);

        // Nothing is fetch-joined, so the count is COUNT(i.id): the default output walker counts a SELECT DISTINCT of every
        // column of the row and its customer, 8.4 s at a million invoices.
        $paginator = new Paginator($query, fetchJoinCollection: false)->setUseOutputWalkers(false);
        /** @var list<Invoice> $invoices */
        $invoices = iterator_to_array($paginator, false);
        $this->loadWhatARowShows($invoices);

        return new Page($invoices, \count($paginator), $page);
    }

    public function statusCounts(Uuid $companyId, InvoiceSearch $search, \DateTimeImmutable $today): array
    {
        // The chips narrow by status themselves, so whatever statuses the search carried are left aside.
        $statusFree = $search->withoutStatus();
        $statuses = array_map(static fn (InvoiceStatus $status): string => $status->value, InvoiceStatus::cases());
        $counts = array_fill_keys($statuses, 0);
        /** @var list<array{status: InvoiceStatus, n: int|string}> $rows the column is mapped to the enum */
        $rows = $this->filtered($companyId, $statusFree)
            ->select('i.status AS status', 'COUNT(i.id) AS n')->groupBy('i.status')
            ->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['n'];
        }
        $all = array_sum($counts);
        // Overdue by the very condition the list narrows with, so the chip and the list it opens cannot disagree.
        $counts[self::OVERDUE] = (int) $this->filtered($companyId, $search->onlyOverdue($today))
            ->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();

        return ['all' => $all, 'statuses' => $counts];
    }

    /** The company's documents narrowed as a search asks, its order and its page left to the caller. */
    private function filtered(Uuid $companyId, InvoiceSearch $search): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->from(Invoice::class, 'i')
            ->join('i.customer', 'c')
            ->where('i.company = :company')->setParameter('company', $companyId, 'uuid');
        $words = trim($search->text ?? '');
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere(self::MATCHES_WORDS)->setParameter('text', SearchText::escapeLike($words));
        } elseif ('' !== $words) {
            $query->andWhere('LOWER(i.number) = LOWER(:number)')->setParameter('number', $words);
        }
        // The values of one filter are OR'd, the filters AND'd. Overdue is one more value of the status filter.
        $status = [];
        if ([] !== $search->statuses) {
            $status[] = 'i.status IN (:statuses)';
            $query->setParameter('statuses', array_map(static fn (InvoiceStatus $each): string => $each->value, $search->statuses), ArrayParameterType::STRING);
        }
        if (null !== $search->overdueOn) {
            $status[] = '(i.documentType = :overdueType AND i.status IN (:overdueStatuses) AND i.dueDate IS NOT NULL AND i.dueDate < :overdueOn)';
            $query->setParameter('overdueType', InvoiceType::Invoice->value)
                ->setParameter('overdueStatuses', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
                ->setParameter('overdueOn', $search->overdueOn);
        }
        if ([] !== $status) {
            $query->andWhere('('.implode(' OR ', $status).')');
        }
        if ([] !== $search->documentTypes) {
            $query->andWhere('i.documentType IN (:documentTypes)')
                ->setParameter('documentTypes', array_map(static fn (InvoiceType $each): string => $each->value, $search->documentTypes), ArrayParameterType::STRING);
        }
        if ([] !== $search->customers) {
            $query->andWhere('i.customer IN (:customerIds)')
                ->setParameter('customerIds', array_map(static fn (Uuid $each): string => $each->toRfc4122(), $search->customers), ArrayParameterType::STRING);
        }
        Intervals::days($query, 'i.issueDate', 'issued', $search->issueDate);
        Intervals::days($query, 'i.dueDate', 'due', $search->dueDate);
        Intervals::sizes($query, 'i.totalGross', 'total', $search->totalGross);
        Intervals::sizes($query, 'i.amountDue', 'owed', $search->amountDue);

        return $query;
    }

    /**
     * A row answers its lines with their taxes and products, its document taxes and its payments. Read one relation
     * at a time for every row of the page, they cost the same few statements for 1 row or 100, where walking them row
     * by row cost 183 statements for 25 (Doctrine, "Improving performance": fetch joins; audit PF-07). Each query only
     * fills collections of documents already in memory, so a page's rows come back fully loaded.
     *
     * @param list<Invoice> $invoices
     */
    private function loadWhatARowShows(array $invoices): void
    {
        if ([] === $invoices) {
            return;
        }
        $ids = array_map(static fn (Invoice $invoice): string => $invoice->getId()->toRfc4122(), $invoices);
        foreach ([
            'SELECT i, l, lt, p FROM '.Invoice::class.' i LEFT JOIN i.lines l LEFT JOIN l.taxes lt LEFT JOIN l.product p WHERE i.id IN (:ids)',
            'SELECT i, t FROM '.Invoice::class.' i LEFT JOIN i.documentTaxes t WHERE i.id IN (:ids)',
            'SELECT i, pay FROM '.Invoice::class.' i LEFT JOIN i.payments pay WHERE i.id IN (:ids)',
        ] as $dql) {
            $this->entityManager->createQuery($dql)->setParameter('ids', $ids, ArrayParameterType::STRING)->getResult();
        }
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Invoice
    {
        $invoice = $this->entityManager->find(Invoice::class, $id);

        return null !== $invoice && $invoice->getCompany()->getId()->equals($companyId) ? $invoice : null;
    }

    public function latestOfCompany(Uuid $companyId): ?Invoice
    {
        // Walks idx_invoice_company_created backwards.
        $invoice = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.company = :company')
            ->andWhere('i.documentType = :invoice')
            ->andWhere('i.status <> :cancelled')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('invoice', InvoiceType::Invoice->value)
            ->setParameter('cancelled', InvoiceStatus::Cancelled->value)
            ->orderBy('i.createdAt', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $invoice instanceof Invoice ? $invoice : null;
    }

    public function lockedOfIdInCompany(Uuid $id, Uuid $companyId): ?Invoice
    {
        $invoice = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.id = :id')
            ->andWhere('i.company = :company')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('company', $companyId, 'uuid')
            ->getQuery()
            // SELECT … FOR UPDATE, which Doctrine refuses outside a transaction; the refresh hint replaces whatever an
            // earlier read of the row in this request left in memory with what the lock just read.
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $invoice instanceof Invoice ? $invoice : null;
    }

    public function givingBack(Uuid $companyId, Uuid $depositId): array
    {
        $invoices = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.company = :company')
            ->andWhere('i.documentType = :type')
            ->andWhere('i.status <> :cancelled')
            ->andWhere('i.id IN (SELECT IDENTITY(l.invoice) FROM '.InvoiceLine::class.' l WHERE l.deductsInvoice = :deposit)')
            ->orderBy('i.createdAt')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('type', InvoiceType::Invoice->value)
            ->setParameter('cancelled', InvoiceStatus::Cancelled->value)
            ->setParameter('deposit', $depositId, 'uuid')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(\is_array($invoices) ? $invoices : [], static fn (mixed $invoice): bool => $invoice instanceof Invoice));
    }

    public function depositsOfQuotes(Uuid $companyId, array $quoteIds): array
    {
        if ([] === $quoteIds) {
            return [];
        }
        $invoices = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.company = :company')
            ->andWhere('i.deposit = true')
            ->andWhere('i.quoteId IN (:quotes)')
            ->orderBy('i.createdAt')
            ->addOrderBy('i.id')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('quotes', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $quoteIds), ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(\is_array($invoices) ? $invoices : [], static fn (mixed $invoice): bool => $invoice instanceof Invoice));
    }

    public function carryingDeliveryNoteLines(Uuid $companyId, array $deliveryNoteLineIds): array
    {
        if ([] === $deliveryNoteLineIds) {
            return [];
        }
        $invoices = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.company = :company')
            ->andWhere('i.documentType = :type')
            ->andWhere('i.status <> :cancelled')
            ->andWhere('i.id IN (SELECT IDENTITY(l.invoice) FROM '.InvoiceLine::class.' l WHERE l.sourceDeliveryNoteLineId IN (:lines))')
            ->orderBy('i.createdAt')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('type', InvoiceType::Invoice->value)
            ->setParameter('cancelled', InvoiceStatus::Cancelled->value)
            ->setParameter('lines', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $deliveryNoteLineIds), ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(\is_array($invoices) ? $invoices : [], static fn (mixed $invoice): bool => $invoice instanceof Invoice));
    }

    public function invoicedQuantities(Uuid $companyId, array $deliveryNoteLineIds, bool $issuedOnly = false, ?Uuid $except = null): array
    {
        if ([] === $deliveryNoteLineIds) {
            return [];
        }
        $excluded = $issuedOnly ? [InvoiceStatus::Cancelled->value, InvoiceStatus::Draft->value] : [InvoiceStatus::Cancelled->value];
        $query = $this->entityManager->createQueryBuilder()
            ->select('l.sourceDeliveryNoteLineId AS line', 'SUM(l.quantity) AS quantity')
            ->from(InvoiceLine::class, 'l')
            ->join('l.invoice', 'i')
            ->where('i.company = :company')
            ->andWhere('i.documentType = :type')
            ->andWhere('i.status NOT IN (:excluded)')
            ->andWhere('l.sourceDeliveryNoteLineId IN (:lines)')
            ->groupBy('l.sourceDeliveryNoteLineId')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('type', InvoiceType::Invoice->value)
            ->setParameter('excluded', $excluded, ArrayParameterType::STRING)
            ->setParameter('lines', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $deliveryNoteLineIds), ArrayParameterType::STRING);
        if (null !== $except) {
            $query->andWhere('i.id <> :except')->setParameter('except', $except, 'uuid');
        }
        $rows = $query->getQuery()->getArrayResult();

        $quantities = [];
        foreach ($rows as $row) {
            if (\is_array($row) && ($row['line'] ?? null) instanceof Uuid && (\is_string($row['quantity'] ?? null) || \is_int($row['quantity'] ?? null) || \is_float($row['quantity'] ?? null))) {
                $quantities[$row['line']->toRfc4122()] = (string) $row['quantity'];
            }
        }

        return $quantities;
    }

    public function numberTaken(Uuid $companyId, InvoiceType $type, string $number): bool
    {
        return null !== $this->entityManager->getRepository(Invoice::class)->findOneBy(['company' => $companyId, 'documentType' => $type, 'number' => $number]);
    }

    public function save(Invoice $invoice): void
    {
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();
    }
}
