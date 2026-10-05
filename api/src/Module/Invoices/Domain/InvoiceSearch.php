<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Shared\Domain\DateRange;
use App\Shared\Domain\DecimalRange;
use Symfony\Component\Uid\Uuid;

/**
 * What an invoices list asks for (docs/SPEC.md § 7, lists at scale; combinable filters, 2026-10-06): words found in the
 * number, the customer's own reference or the customer as the document recorded them, whatever their case and accents
 * (under three characters, the exact number only), the statuses, the kinds of document and the customers wanted, the
 * issue and due days and the total and amount due as intervals, and the order. The values of one filter are OR'd and
 * different filters are AND'd.
 *
 * The searchable text is the invoice's own, so that one trigram index answers it. A DRAFT is therefore searchable by
 * the customer's reference but not by the customer's name: the name is copied onto the document when it is issued.
 * Pick the customer to narrow a draft list — that is what `customers` is for. A draft has no issue day, due day or
 * total yet, so an interval on one leaves the drafts out.
 */
final readonly class InvoiceSearch
{
    public const array SORTS = ['number', 'customer', 'issueDate', 'dueDate', 'status'];

    /**
     * @param list<InvoiceStatus>         $statuses
     * @param list<InvoiceType>           $documentTypes
     * @param list<Uuid>                  $customers
     * @param array<string, 'asc'|'desc'> $order         one of SORTS per key, in the order it applies
     * @param ?\DateTimeImmutable         $overdueOn     the company's own day, when overdue is among the statuses wanted: an
     *                                                   invoice, issued or partly paid, whose due day has passed. Overdue is
     *                                                   what a person sees in the status column and not a status the
     *                                                   document holds, so it is asked for as one and answered by the same
     *                                                   rule the screen shows (docs/SPEC.md § 7, 2026-09-16), OR'd with the
     *                                                   statuses.
     */
    public function __construct(
        public ?string $text = null,
        public array $statuses = [],
        public array $documentTypes = [],
        public array $customers = [],
        public array $order = [],
        public ?\DateTimeImmutable $overdueOn = null,
        public ?DateRange $issueDate = null,
        public ?DateRange $dueDate = null,
        public ?DecimalRange $totalGross = null,
        public ?DecimalRange $amountDue = null,
    ) {
    }

    /** The same search with no status named, which is what a status chip's count is made under. */
    public function withoutStatus(): self
    {
        return new self($this->text, [], $this->documentTypes, $this->customers, [], null, $this->issueDate, $this->dueDate, $this->totalGross, $this->amountDue);
    }

    /** The same search asking only for what is overdue on that day: what the overdue chip would list. */
    public function onlyOverdue(\DateTimeImmutable $today): self
    {
        return new self($this->text, [], $this->documentTypes, $this->customers, [], $today, $this->issueDate, $this->dueDate, $this->totalGross, $this->amountDue);
    }
}
