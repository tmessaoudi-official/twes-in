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
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineInvoiceRepository implements InvoiceRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        return $this->entityManager->getRepository(Invoice::class)->findBy(['company' => $companyId], ['createdAt' => 'DESC', 'id' => 'DESC']);
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
