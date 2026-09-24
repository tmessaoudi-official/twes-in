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
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\SearchText;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
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
        $query = $this->entityManager->createQueryBuilder()
            ->select('i', 'c')->from(Invoice::class, 'i')
            ->join('i.customer', 'c')
            ->where('i.company = :company')->setParameter('company', $companyId, 'uuid');
        $words = trim($search->text ?? '');
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere(self::MATCHES_WORDS)->setParameter('text', SearchText::escapeLike($words));
        } elseif ('' !== $words) {
            $query->andWhere('LOWER(i.number) = LOWER(:number)')->setParameter('number', $words);
        }
        if (null !== $search->status) {
            $query->andWhere('i.status = :status')->setParameter('status', $search->status->value);
        }
        if (null !== $search->documentType) {
            $query->andWhere('i.documentType = :documentType')->setParameter('documentType', $search->documentType->value);
        }
        if (null !== $search->customer) {
            $query->andWhere('i.customer = :customerId')->setParameter('customerId', $search->customer, 'uuid');
        }
        if (null !== $search->overdueOn) {
            $query->andWhere('i.documentType = :overdueType AND i.status IN (:overdueStatuses) AND i.dueDate IS NOT NULL AND i.dueDate < :overdueOn')
                ->setParameter('overdueType', InvoiceType::Invoice->value)
                ->setParameter('overdueStatuses', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
                ->setParameter('overdueOn', $search->overdueOn);
        }
        // A draft has no number, so the number cannot settle a tie the way a product's reference does: the newest
        // first, and the id last, which is unique and never null.
        ListOrder::apply($query, $search->order, self::SORTED_BY, ['number', 'issueDate', 'dueDate'], 'i.createdAt', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setFirstResult($page->offset())->setMaxResults($page->size);

        $paginator = new Paginator($query, fetchJoinCollection: false);
        /** @var list<Invoice> $invoices */
        $invoices = iterator_to_array($paginator, false);
        $this->loadWhatARowShows($invoices);

        return new Page($invoices, \count($paginator), $page);
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
