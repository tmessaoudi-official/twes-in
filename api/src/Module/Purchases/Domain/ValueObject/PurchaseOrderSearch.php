<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Domain\ValueObject;

use App\Shared\Domain\ValueObject\DateRange;
use Symfony\Component\Uid\Uuid;

/**
 * What an orders list asks for: words found in the number or the vendor's name, whatever their case and accents (under
 * three characters, the exact number only), the statuses and vendors wanted, the issue and expected days as intervals,
 * and the order. The values of one filter are OR'd and different filters are AND'd. A draft has no issue day yet, so an
 * interval on it leaves the drafts out.
 */
final readonly class PurchaseOrderSearch
{
    public const array SORTS = ['number', 'vendor', 'issueDate', 'expectedOn', 'status'];

    /**
     * @param list<PurchaseOrderStatus>   $statuses
     * @param list<Uuid>                  $vendors
     * @param array<string, 'asc'|'desc'> $order    one of SORTS per key, in the order it applies
     */
    public function __construct(
        public ?string $text = null,
        public array $statuses = [],
        public array $vendors = [],
        public array $order = [],
        public ?DateRange $issueDate = null,
        public ?DateRange $expectedOn = null,
    ) {
    }

    /** The same search with no status named, which is what a status chip's count is made under. */
    public function withoutStatus(): self
    {
        return new self($this->text, [], $this->vendors, [], $this->issueDate, $this->expectedOn);
    }
}
