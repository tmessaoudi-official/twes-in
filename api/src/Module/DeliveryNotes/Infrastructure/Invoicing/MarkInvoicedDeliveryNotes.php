<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Invoicing;

use App\Module\DeliveryNotes\Application\InvoiceDeliveryNotes;
use App\Module\Invoices\Domain\InvoiceIssued;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Marks the delivery notes an issued invoice's lines came from invoiced (docs/SPEC.md § 7, 2026-09-14). The issue is
 * already committed: a note that can no longer be marked is left as it is and logged.
 */
#[AsEventListener(event: InvoiceIssued::class)]
final readonly class MarkInvoicedDeliveryNotes
{
    public function __construct(private InvoiceDeliveryNotes $invoicing, private LoggerInterface $logger)
    {
    }

    public function __invoke(InvoiceIssued $event): void
    {
        foreach ($this->invoicing->markInvoiced($event->companyId, $event->invoiceId, $event->number, $event->sourceDeliveryNoteLineIds) as $reason) {
            $this->logger->warning('Invoice {number} was issued, but a delivery note it invoices was left as it was: {reason}', [
                'number' => $event->number,
                'reason' => $reason,
            ]);
        }
    }
}
