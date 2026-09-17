<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * What an invoices list asks for (docs/SPEC.md § 7, lists at scale): words found in the number, the customer's own
 * reference or the customer as the document recorded them, whatever their case and accents (under three characters,
 * the exact number only), a status, a kind of document, one customer, and the order.
 *
 * The searchable text is the invoice's own, so that one trigram index answers it. A DRAFT is therefore searchable by
 * the customer's reference but not by the customer's name: the name is copied onto the document when it is issued.
 * Pick the customer to narrow a draft list — that is what `customer` is for.
 */
final readonly class InvoiceSearch
{
    public const array SORTS = ['number', 'customer', 'issueDate', 'dueDate', 'status'];

    /**
     * @param array<string, 'asc'|'desc'> $order     one of SORTS per key, in the order it applies
     * @param ?\DateTimeImmutable         $overdueOn the company's own day, when the list asks for what is overdue on
     *                                               it: an invoice, issued or partly paid, whose due day has passed.
     *                                               Overdue is what a person sees in the status column and not a
     *                                               status the document holds, so it is asked for as one and answered
     *                                               by the same rule the screen shows (docs/SPEC.md § 7, 2026-09-16).
     */
    public function __construct(
        public ?string $text = null,
        public ?InvoiceStatus $status = null,
        public ?InvoiceType $documentType = null,
        public ?Uuid $customer = null,
        public array $order = [],
        public ?\DateTimeImmutable $overdueOn = null,
    ) {
    }
}
