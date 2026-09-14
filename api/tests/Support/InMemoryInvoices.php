<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceType;
use App\Shared\Application\Transactions;
use Symfony\Component\Uid\Uuid;

final class InMemoryInvoices implements InvoiceRepository
{
    /** @var list<Invoice> */
    public array $invoices = [];

    /** When given, a lock is refused outside its transaction, as the database refuses one. */
    public ?Transactions $transactions = null;

    public function ofCompany(Uuid $companyId): array
    {
        $mine = array_values(array_filter($this->invoices, static fn (Invoice $i) => $i->getCompany()->getId()->equals($companyId)));
        usort($mine, static fn (Invoice $a, Invoice $b) => [$b->getCreatedAt(), $b->getId()->toRfc4122()] <=> [$a->getCreatedAt(), $a->getId()->toRfc4122()]);

        return $mine;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Invoice
    {
        foreach ($this->ofCompany($companyId) as $invoice) {
            if ($invoice->getId()->equals($id)) {
                return $invoice;
            }
        }

        return null;
    }

    public function lockedOfIdInCompany(Uuid $id, Uuid $companyId): ?Invoice
    {
        if (null !== $this->transactions && !$this->transactions->active()) {
            throw new \LogicException('An invoice is locked inside a transaction.');
        }

        return $this->ofIdInCompany($id, $companyId);
    }

    public function numberTaken(Uuid $companyId, InvoiceType $type, string $number): bool
    {
        return [] !== array_filter($this->ofCompany($companyId), static fn (Invoice $invoice): bool => $invoice->getType() === $type && $invoice->getNumber() === $number);
    }

    public function save(Invoice $invoice): void
    {
        if (!\in_array($invoice, $this->invoices, true)) {
            $this->invoices[] = $invoice;
        }
    }
}
