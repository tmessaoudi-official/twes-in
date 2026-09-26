<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceLine;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceSearch;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceType;
use App\Shared\Application\Transactions;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use Symfony\Component\Uid\Uuid;

final class InMemoryInvoices implements InvoiceRepository
{
    /** @var list<Invoice> */
    public array $invoices = [];

    /** When given, a lock is refused outside its transaction, as the database refuses one. */
    public ?Transactions $transactions = null;

    /**
     * What an unlocked read hands out instead of the stored invoice: a copy read before another request committed.
     * A locked read waits for that commit and reads the row as it stands, as `FOR UPDATE` with a refresh does.
     *
     * @var array<string, Invoice>
     */
    public array $staleReads = [];

    public function ofCompany(Uuid $companyId): array
    {
        $mine = array_values(array_filter($this->invoices, static fn (Invoice $i) => $i->getCompany()->getId()->equals($companyId)));
        usort($mine, static fn (Invoice $a, Invoice $b) => [$b->getCreatedAt(), $b->getId()->toRfc4122()] <=> [$a->getCreatedAt(), $a->getId()->toRfc4122()]);

        return $mine;
    }

    /**
     * The page the database would answer is the database's own job — searching, narrowing and ordering are SQL here,
     * and a unit test that wants them tests the real repository. This one pages what it holds, so a caller reading a
     * page still reads rows, and says plainly that it ignores the rest.
     */
    public function search(Uuid $companyId, InvoiceSearch $search, PageRequest $page): Page
    {
        $mine = $this->ofCompany($companyId);

        return new Page(\array_slice($mine, $page->offset(), $page->size), \count($mine), $page);
    }

    public function statusCounts(Uuid $companyId, InvoiceSearch $search, \DateTimeImmutable $today): array
    {
        $counts = array_fill_keys([...array_map(static fn (InvoiceStatus $status): string => $status->value, InvoiceStatus::cases()), 'overdue'], 0);
        $mine = $this->ofCompany($companyId);
        foreach ($mine as $invoice) {
            ++$counts[$invoice->getStatus()->value];
            $due = $invoice->getDueDate();
            if (InvoiceType::Invoice === $invoice->getType() && \in_array($invoice->getStatus(), [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true) && null !== $due && $due < $today) {
                ++$counts['overdue'];
            }
        }

        return ['all' => \count($mine), 'statuses' => $counts];
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Invoice
    {
        $stale = $this->staleReads[$id->toRfc4122()] ?? null;
        if (null !== $stale && $stale->getCompany()->getId()->equals($companyId)) {
            return $stale;
        }

        return $this->stored($id, $companyId);
    }

    private function stored(Uuid $id, Uuid $companyId): ?Invoice
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

        return $this->stored($id, $companyId);
    }

    public function carryingDeliveryNoteLines(Uuid $companyId, array $deliveryNoteLineIds): array
    {
        $wanted = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $deliveryNoteLineIds);

        return array_values(array_filter($this->ofCompany($companyId), static fn (Invoice $invoice): bool => InvoiceType::Invoice === $invoice->getType()
            && InvoiceStatus::Cancelled !== $invoice->getStatus()
            && [] !== array_filter($invoice->getLines(), static fn (InvoiceLine $line): bool => \in_array($line->getSourceDeliveryNoteLineId()?->toRfc4122(), $wanted, true))));
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
