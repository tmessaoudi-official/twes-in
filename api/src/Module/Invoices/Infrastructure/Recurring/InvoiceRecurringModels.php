<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Recurring;

use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceType;
use App\Module\Recurring\Application\RecurringModel;
use App\Module\Recurring\Application\RecurringModels;
use App\Tenancy\Domain\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What a recurring invoice shows of the invoice it copies: its number, its customer, and whether it is copied at all.
 * An invoice is; a credit note corrects another document, a deposit invoice is the advance of one sale, and a cancelled
 * invoice is a sale that no longer happens.
 */
final readonly class InvoiceRecurringModels implements RecurringModels
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function describe(Company $company, Uuid $invoiceId): ?RecurringModel
    {
        return $this->describeAll($company, [$invoiceId])[$invoiceId->toRfc4122()] ?? null;
    }

    public function describeAll(Company $company, array $invoiceIds): array
    {
        if ([] === $invoiceIds) {
            return [];
        }
        $found = $this->entityManager->createQueryBuilder()
            ->select('i')->from(Invoice::class, 'i')
            ->where('i.company = :company')->andWhere('i.id IN (:ids)')
            ->setParameter('company', $company->getId(), 'uuid')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $invoiceIds))
            ->getQuery()->getResult();
        $models = [];
        foreach (\is_array($found) ? $found : [] as $invoice) {
            if (!$invoice instanceof Invoice) {
                continue;
            }
            $customer = $invoice->getCustomer();
            $models[$invoice->getId()->toRfc4122()] = new RecurringModel(
                $invoice->getNumber(),
                $customer->getProfile()->name,
                InvoiceType::Invoice === $invoice->getType() && !$invoice->isDeposit() && InvoiceStatus::Cancelled !== $invoice->getStatus(),
            );
        }

        return $models;
    }
}
