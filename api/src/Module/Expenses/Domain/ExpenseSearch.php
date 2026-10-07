<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain;

use App\Shared\Domain\DateRange;
use App\Shared\Domain\DecimalRange;
use App\Shared\Domain\PaymentMethod;
use Symfony\Component\Uid\Uuid;

/**
 * What an expenses list asks for (docs/SPEC.md § 7, lists at scale, and 2026-10-06 00:15): words found in what the
 * expense is for or in the vendor's own reference on it, whatever their case and accents, then filters that combine:
 * the values of one are OR'd (several statuses, several vendors), different ones are AND'd, and the day and the amount
 * taxes included are intervals, each end inclusive.
 *
 * The searchable text is the expense's own, so that one trigram index answers it. The vendor's and the category's
 * names live on their own rows and are not copied here, so pick them to narrow the list — that is what `vendors` and
 * `categories` are for. A category stands for itself and every category under it: the use case widens it before the
 * search reaches the repository. Sorting by either reads the joined name, which no index has to answer because the
 * sort applies to one page.
 *
 * The due day is not among the sorts: it is the vendor's payment terms counted from the expense's day, worked out when
 * the expense is read and not a column of its own, so the database cannot order by it.
 */
final readonly class ExpenseSearch
{
    public const array SORTS = ['date', 'description', 'vendor', 'category', 'amountGross', 'status'];

    /**
     * @param list<ExpenseStatus>         $statuses       any of them; none is every status
     * @param list<Uuid>                  $vendors        any of them
     * @param list<Uuid>                  $categories     any of them, each standing for itself (the use case adds what sits under it)
     * @param array<string, 'asc'|'desc'> $order          one of SORTS per key, in the order it applies
     * @param list<PaymentMethod>         $paymentMethods any of them; an expense not paid yet has none and is left out by any
     * @param bool|null                   $withheld       true: something was withheld when it was paid; false: nothing was; null: either
     */
    public function __construct(
        public ?string $text = null,
        public array $statuses = [],
        public array $vendors = [],
        public array $categories = [],
        public array $order = [],
        public array $paymentMethods = [],
        public ?bool $withheld = null,
        public ?DateRange $date = null,
        public ?DecimalRange $amountGross = null,
    ) {
    }

    /**
     * The same search under other categories: what the use case hands the repository once it has widened them.
     *
     * @param list<Uuid> $categories
     */
    public function withCategories(array $categories): self
    {
        return new self($this->text, $this->statuses, $this->vendors, $categories, $this->order, $this->paymentMethods, $this->withheld, $this->date, $this->amountGross);
    }

    /** The same search whatever the status, which is what the status chips count under. */
    public function withoutStatus(): self
    {
        return new self($this->text, [], $this->vendors, $this->categories, [], $this->paymentMethods, $this->withheld, $this->date, $this->amountGross);
    }
}
