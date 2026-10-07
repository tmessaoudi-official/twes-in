<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Domain;

use App\Shared\Domain\DateRange;
use Symfony\Component\Uid\Uuid;

/**
 * What a quotes list asks for: words found in the number, the customer's own reference or the customer as the quote
 * recorded them, whatever their case and accents (under three characters, the exact number only), the statuses and the
 * customers wanted, the issue day as an interval, and the order. The values of one filter are OR'd and different
 * filters are AND'd. A draft has no issue day yet, so an interval leaves the drafts out, and it is searchable by the
 * customer's reference but not by the customer's name, copied onto the quote when it is sent: pick the customer.
 */
final readonly class QuoteSearch
{
    public const array SORTS = ['number', 'customer', 'issueDate', 'validUntil', 'status'];

    /**
     * @param list<QuoteStatus>           $statuses
     * @param list<Uuid>                  $customers
     * @param array<string, 'asc'|'desc'> $order     one of SORTS per key, in the order it applies
     */
    public function __construct(
        public ?string $text = null,
        public array $statuses = [],
        public array $customers = [],
        public array $order = [],
        public ?DateRange $issueDate = null,
    ) {
    }

    /** The same search with no status named, which is what a status chip's count is made under. */
    public function withoutStatus(): self
    {
        return new self($this->text, [], $this->customers, [], $this->issueDate);
    }
}
