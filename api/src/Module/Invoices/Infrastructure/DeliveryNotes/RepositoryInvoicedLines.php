<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\DeliveryNotes;

use App\Module\DeliveryNotes\Application\CarryingInvoice;
use App\Module\DeliveryNotes\Application\InvoicedLines;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Domain\Repository\InvoiceRepository;
use Symfony\Component\Uid\Uuid;

/** Answers the delivery notes' `InvoicedLines` port from this module's invoices. */
final readonly class RepositoryInvoicedLines implements InvoicedLines
{
    public function __construct(private InvoiceRepository $invoices)
    {
    }

    public function taken(Uuid $companyId, array $deliveryNoteLineIds, bool $issuedOnly = false): array
    {
        return $this->invoices->invoicedQuantities($companyId, $deliveryNoteLineIds, $issuedOnly);
    }

    public function carrying(Uuid $companyId, array $deliveryNoteLineIds): array
    {
        $asked = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $deliveryNoteLineIds);

        return array_map(static fn (Invoice $invoice): CarryingInvoice => new CarryingInvoice($invoice->getId(), $invoice->getNumber(), $invoice->getStatus()->value, self::carried($invoice, $asked)), $this->invoices->carryingDeliveryNoteLines($companyId, $deliveryNoteLineIds));
    }

    /**
     * @param list<string> $asked
     *
     * @return list<string> the lines asked about that the invoice's lines come from, each once
     */
    private static function carried(Invoice $invoice, array $asked): array
    {
        $carried = [];
        foreach ($invoice->getLines() as $line) {
            $source = $line->getSourceDeliveryNoteLineId()?->toRfc4122();
            if (null !== $source && \in_array($source, $asked, true)) {
                $carried[$source] = $source;
            }
        }

        return array_values($carried);
    }
}
