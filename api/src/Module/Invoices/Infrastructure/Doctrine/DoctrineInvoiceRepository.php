<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Doctrine;

use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceType;
use Doctrine\ORM\EntityManagerInterface;
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
