<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fiscal\Domain\Calculation\Decimal;
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

    public function latestOfCompany(Uuid $companyId): ?Invoice
    {
        foreach ($this->ofCompany($companyId) as $invoice) {
            if (InvoiceType::Invoice === $invoice->getType() && InvoiceStatus::Cancelled !== $invoice->getStatus()) {
                return $invoice;
            }
        }

        return null;
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

    /** What is due is worked out from the payments in SQL: this one reads each late invoice's amount due as issued. */
    public function overdueOf(Uuid $companyId, Uuid $customerId, \DateTimeImmutable $today): array
    {
        $amount = Decimal::zero();
        $count = 0;
        $oldest = null;
        foreach ($this->ofCompany($companyId) as $invoice) {
            $due = $invoice->getDueDate();
            if ($invoice->getCustomer()->getId()->equals($customerId) && InvoiceType::Invoice === $invoice->getType() && \in_array($invoice->getStatus(), [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true) && null !== $due && $due < $today) {
                $figures = $invoice->getIssuedFigures();
                $amount = $amount->add(Decimal::of(null === $figures ? '0' : $figures->amountDue));
                ++$count;
                $oldest = null === $oldest ? $due : min($oldest, $due);
            }
        }

        return ['amount' => $amount->value, 'count' => $count, 'oldestDueDate' => $oldest];
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

    public function invoicedQuantities(Uuid $companyId, array $deliveryNoteLineIds, bool $issuedOnly = false, ?Uuid $except = null): array
    {
        $wanted = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $deliveryNoteLineIds);
        $excluded = $issuedOnly ? [InvoiceStatus::Cancelled, InvoiceStatus::Draft] : [InvoiceStatus::Cancelled];
        $quantities = [];
        foreach ($this->ofCompany($companyId) as $invoice) {
            if (InvoiceType::Invoice !== $invoice->getType() || \in_array($invoice->getStatus(), $excluded, true) || (null !== $except && $invoice->getId()->equals($except))) {
                continue;
            }
            foreach ($invoice->getLines() as $line) {
                $source = $line->getSourceDeliveryNoteLineId()?->toRfc4122();
                if (null !== $source && \in_array($source, $wanted, true)) {
                    $quantities[$source] = (string) Decimal::of($quantities[$source] ?? '0')->add(Decimal::of($line->getQuantity()));
                }
            }
        }

        return $quantities;
    }

    public function givingBack(Uuid $companyId, Uuid $depositId): array
    {
        return array_values(array_filter($this->ofCompany($companyId), static fn (Invoice $invoice): bool => InvoiceType::Invoice === $invoice->getType()
            && InvoiceStatus::Cancelled !== $invoice->getStatus()
            && array_any($invoice->getLines(), static fn (InvoiceLine $line): bool => true === $line->getDeduction()?->deposit->getId()->equals($depositId))));
    }

    public function depositsOfQuotes(Uuid $companyId, array $quoteIds): array
    {
        $wanted = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $quoteIds);

        return array_values(array_filter($this->ofCompany($companyId), static fn (Invoice $invoice): bool => $invoice->isDeposit() && \in_array($invoice->getQuoteId()?->toRfc4122(), $wanted, true)));
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
