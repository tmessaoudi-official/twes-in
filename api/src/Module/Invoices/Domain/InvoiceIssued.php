<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Shared\Domain\DomainEvent;
use Symfony\Component\Uid\Uuid;

/** `invoice.issued`: an invoice or a credit note was numbered and its figures fixed. */
final readonly class InvoiceIssued implements DomainEvent
{
    /**
     * @param list<Uuid>             $sourceDeliveryNoteLineIds the delivery note lines its lines came from, none for a document drafted by hand
     * @param list<InvoicedQuantity> $directLines               the product lines of an invoice that no delivery note handed over, so the goods leave with this document; always empty for a credit note
     * @param Uuid|null              $correctsInvoiceId         the invoice a credit note corrects; none for an invoice
     * @param list<InvoicedQuantity> $returnedLines             the product lines of a credit note the person marked as returned, whose goods come back to what the corrected invoice took out; always empty for an invoice
     */
    public function __construct(
        public Uuid $invoiceId,
        public Uuid $companyId,
        public Uuid $establishmentId,
        public InvoiceType $type,
        public string $number,
        public \DateTimeImmutable $issueDate,
        public array $sourceDeliveryNoteLineIds,
        public array $directLines = [],
        public ?Uuid $correctsInvoiceId = null,
        public array $returnedLines = [],
    ) {
    }
}
