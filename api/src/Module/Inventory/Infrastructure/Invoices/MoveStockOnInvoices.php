<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Invoices;

use App\Module\DeliveryNotes\Domain\DeliveredQuantity;
use App\Module\Inventory\Application\MoveStockForDeliveryNotes;
use App\Module\Inventory\Application\SourceDeliveryNotes;
use App\Module\Inventory\Application\TellStockKeepers;
use App\Module\Invoices\Domain\InvoicedQuantity;
use App\Module\Invoices\Domain\InvoiceIssued;
use App\Module\Invoices\Domain\InvoiceType;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Takes an invoice's own goods out of stock when it is issued, and brings back what a credit note's returned lines name
 * when the credit note is, from the invoice's own sale or from the delivery notes it was built from. The document is
 * already committed, so nothing here throws: a line that moved no stock, or a move that failed, is logged and told to the
 * people who keep the company's stock. A credit note takes nothing out, and an invoice takes nothing a delivery note
 * already handed over.
 */
#[AsEventListener(event: InvoiceIssued::class)]
final readonly class MoveStockOnInvoices
{
    public function __construct(private MoveStockForDeliveryNotes $move, private TellStockKeepers $tell, private LoggerInterface $logger, private SourceDeliveryNotes $notes)
    {
    }

    public function __invoke(InvoiceIssued $event): void
    {
        match ($event->type) {
            InvoiceType::CreditNote => $this->returned($event),
            InvoiceType::Invoice => $this->sold($event),
        };
    }

    private function sold(InvoiceIssued $event): void
    {
        if ([] === $event->directLines) {
            return;
        }
        $lines = array_map(static fn (InvoicedQuantity $line): DeliveredQuantity => new DeliveredQuantity($line->productId, $line->quantity, $line->unitId, $line->lotCode), $event->directLines);
        try {
            $reasons = $this->move->invoiced($event->invoiceId, $event->companyId, $event->establishmentId, $lines);
        } catch (\Throwable $failure) {
            $this->logger->error('Invoice {number} was issued, but its stock could not be moved: {failure}.', ['number' => $event->number, 'failure' => $failure->getMessage(), 'exception' => $failure]);
            $this->told(fn () => $this->tell->invoiceMovedNoStock($event->companyId, $event->invoiceId, $event->number));

            return;
        }
        foreach ($reasons as $reason) {
            $this->logger->warning('Invoice {number} was issued, but {reason}.', ['number' => $event->number, 'reason' => $reason]);
        }
        if ([] !== $reasons) {
            $this->told(fn () => $this->tell->invoiceLeftLinesOut($event->companyId, $event->invoiceId, $event->number));
        }
    }

    private function returned(InvoiceIssued $event): void
    {
        if (null === $event->correctsInvoiceId || [] === $event->returnedLines) {
            return;
        }
        $lines = array_map(static fn (InvoicedQuantity $line): DeliveredQuantity => new DeliveredQuantity($line->productId, $line->quantity, $line->unitId, $line->lotCode), $event->returnedLines);
        try {
            $reasons = $this->move->returned($event->invoiceId, $event->correctsInvoiceId, $event->companyId, $lines, $this->notes->ofLines($event->correctedSourceLineIds, $event->companyId));
        } catch (\Throwable $failure) {
            $this->logger->error('Credit note {number} was issued, but its goods could not be returned to stock: {failure}.', ['number' => $event->number, 'failure' => $failure->getMessage(), 'exception' => $failure]);
            $this->told(fn () => $this->tell->creditMovedNoStock($event->companyId, $event->invoiceId, $event->number));

            return;
        }
        foreach ($reasons as $reason) {
            $this->logger->warning('Credit note {number} was issued, but {reason}.', ['number' => $event->number, 'reason' => $reason]);
        }
        if ([] !== $reasons) {
            $this->told(fn () => $this->tell->creditLinesNotReturned($event->companyId, $event->invoiceId, $event->number));
        }
    }

    /** Telling is best effort too: a notification that cannot be written leaves the log line as the record. */
    private function told(\Closure $tell): void
    {
        try {
            $tell();
        } catch (\Throwable $failure) {
            $this->logger->error('Stock keepers could not be told: {failure}.', ['failure' => $failure->getMessage(), 'exception' => $failure]);
        }
    }
}
