<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

use App\Shared\Domain\DateRange;
use Symfony\Component\Uid\Uuid;

/**
 * What a delivery notes list asks for (docs/SPEC.md § 7, lists at scale): words found in the number, the customer's
 * own reference or the customer as the note recorded them, whatever their case and accents (under three characters,
 * the exact number only), the statuses and the customers wanted, the issue and delivery days as intervals, and the
 * order (combinable filters, docs/SPEC.md § 7, 2026-10-06). The values of one filter are OR'd and different filters are
 * AND'd. A draft has no issue day yet, so an interval on it leaves the drafts out.
 *
 * The searchable text is the note's own, so that one trigram index answers it. A DRAFT is therefore searchable by the
 * customer's reference but not by the customer's name: the name is copied onto the note when it is validated. Pick the
 * customer to narrow a draft list — that is what `customers` is for.
 */
final readonly class DeliveryNoteSearch
{
    public const array SORTS = ['number', 'customer', 'issueDate', 'deliveryDate', 'status'];

    /**
     * @param list<DeliveryNoteStatus>    $statuses
     * @param list<Uuid>                  $customers
     * @param array<string, 'asc'|'desc'> $order     one of SORTS per key, in the order it applies
     */
    public function __construct(
        public ?string $text = null,
        public array $statuses = [],
        public array $customers = [],
        public array $order = [],
        public ?DateRange $issueDate = null,
        public ?DateRange $deliveryDate = null,
    ) {
    }

    /** The same search with no status named, which is what a status chip's count is made under. */
    public function withoutStatus(): self
    {
        return new self($this->text, [], $this->customers, [], $this->issueDate, $this->deliveryDate);
    }
}
