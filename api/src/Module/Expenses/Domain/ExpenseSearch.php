<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * What an expenses list asks for (docs/SPEC.md § 7, lists at scale): words found in what the expense is for or in the
 * vendor's own reference on it, whatever their case and accents, a status, one vendor, one category, and the order.
 *
 * The searchable text is the expense's own, so that one trigram index answers it. The vendor's and the category's
 * names live on their own rows and are not copied here, so pick them to narrow the list — that is what `vendor` and
 * `category` are for. Sorting by either reads the joined name, which no index has to answer because the sort applies
 * to one page.
 *
 * The due day is not among the sorts: it is the vendor's payment terms counted from the expense's day, worked out when
 * the expense is read and not a column of its own, so the database cannot order by it.
 */
final readonly class ExpenseSearch
{
    public const array SORTS = ['date', 'description', 'vendor', 'category', 'amountGross', 'status'];

    /** @param array<string, 'asc'|'desc'> $order one of SORTS per key, in the order it applies */
    public function __construct(
        public ?string $text = null,
        public ?ExpenseStatus $status = null,
        public ?Uuid $vendor = null,
        public ?Uuid $category = null,
        public array $order = [],
    ) {
    }
}
