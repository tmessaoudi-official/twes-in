<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Shared\Domain\DomainEvent;
use Symfony\Component\Uid\Uuid;

/** `invoice.issued`: an invoice or a credit note was numbered and its figures fixed (docs/SPEC.md § 7, 2026-09-14). */
final readonly class InvoiceIssued implements DomainEvent
{
    /** @param list<Uuid> $sourceDeliveryNoteLineIds the delivery note lines its lines came from, none for a document drafted by hand */
    public function __construct(
        public Uuid $invoiceId,
        public Uuid $companyId,
        public Uuid $establishmentId,
        public InvoiceType $type,
        public string $number,
        public \DateTimeImmutable $issueDate,
        public array $sourceDeliveryNoteLineIds,
    ) {
    }
}
