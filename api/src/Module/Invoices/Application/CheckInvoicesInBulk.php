<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceType;
use App\Module\Invoices\Domain\PaymentInstrumentRepository;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What a bulk action would do with each invoice a person selected, before it runs, as every bulk action is previewed:
 * each id once, in the order chosen, included or skipped with its reason, so that nothing is left out without being
 * named. Another company's invoice reads as not found, as it does everywhere else.
 */
final readonly class CheckInvoicesInBulk
{
    /** A selection is one list page at most, and the largest page holds this many rows. */
    public const int MOST = 100;

    public function __construct(private InvoiceRepository $invoices, private PaymentInstrumentRepository $instruments, private ClockInterface $clock)
    {
    }

    /**
     * @param list<Uuid>          $ids
     * @param bool                $drafts    whether a PDF takes the drafts, which it otherwise leaves out
     * @param bool                $cancelled whether a PDF takes the cancelled ones, which it otherwise leaves out
     * @param ?\DateTimeImmutable $day       the day a payment is dated, which paying needs
     *
     * @return list<BulkCheckRow>
     *
     * @throws \InvalidArgumentException on no id or too many, or a payment with no day or dated after today
     */
    public function check(Company $company, BulkAction $action, array $ids, bool $drafts = false, bool $cancelled = false, ?\DateTimeImmutable $day = null): array
    {
        if (BulkAction::Pay === $action) {
            $today = $this->clock->now()->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d');
            if (null === $day || $day->format('Y-m-d') > $today) {
                throw new \InvalidArgumentException(\sprintf('A payment is dated today at the latest, %s.', $today));
            }
        }
        $unique = [];
        foreach ($ids as $id) {
            $unique[$id->toRfc4122()] ??= $id;
        }
        if ([] === $unique || \count($unique) > self::MOST) {
            throw new \InvalidArgumentException(\sprintf('A bulk action takes 1 to %d invoices.', self::MOST));
        }
        $found = [];
        foreach ($this->invoices->ofIdsInCompany(array_values($unique), $company->getId()) as $invoice) {
            $found[$invoice->getId()->toRfc4122()] = $invoice;
        }

        $rows = [];
        foreach ($unique as $key => $id) {
            $invoice = $found[$key] ?? null;
            $rows[] = match (true) {
                null === $invoice => new BulkCheckRow($id, null, BulkCheckRow::NOT_FOUND),
                BulkAction::Pay === $action => $this->toPay($company, $id, $invoice, $day),
                default => new BulkCheckRow($id, $invoice, BulkAction::Pdf === $action ? match ($invoice->getStatus()) {
                    InvoiceStatus::Draft => $drafts ? null : BulkCheckRow::DRAFT,
                    InvoiceStatus::Cancelled => $cancelled ? null : BulkCheckRow::CANCELLED,
                    default => null,
                } : null),
            };
        }

        return $rows;
    }

    /**
     * An invoice is paid in bulk for all it still owes, or not at all: the reasons it cannot be are the ones a payment
     * recorded on it alone would meet, said before rather than refused one by one.
     */
    private function toPay(Company $company, Uuid $id, Invoice $invoice, \DateTimeImmutable $day): BulkCheckRow
    {
        $due = $invoice->getIssuedFigures()?->amountDue;
        $skipped = match (true) {
            InvoiceStatus::Draft === $invoice->getStatus() => BulkCheckRow::DRAFT,
            InvoiceStatus::Cancelled === $invoice->getStatus() => BulkCheckRow::CANCELLED,
            InvoiceType::CreditNote === $invoice->getType() => BulkCheckRow::CREDIT_NOTE,
            null === $due || Decimal::of($due)->compare(0) <= 0 => BulkCheckRow::PAID,
            Decimal::of($this->instruments->openAmount($company->getId(), $invoice->getId()))->compare(0) > 0 => BulkCheckRow::INSTRUMENT_HELD,
            ($invoice->getIssueDate()?->format('Y-m-d') ?? '') > $day->format('Y-m-d') => BulkCheckRow::ISSUED_AFTER,
            default => null,
        };

        return new BulkCheckRow($id, $invoice, $skipped, null === $skipped ? $due : null);
    }
}
