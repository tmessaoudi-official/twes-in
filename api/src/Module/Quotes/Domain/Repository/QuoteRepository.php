<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Domain\Repository;

use App\Module\Quotes\Domain\Model\Quote;
use App\Module\Quotes\Domain\ValueObject\QuoteSearch;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use Symfony\Component\Uid\Uuid;

interface QuoteRepository
{
    /**
     * One page of a company's quotes, searched, narrowed and sorted by the database.
     *
     * @return Page<Quote>
     */
    public function search(Uuid $companyId, QuoteSearch $search, PageRequest $page): Page;

    /**
     * How many quotes each status chip of the list would show under the same words and customer, the status left
     * aside, and how many in all.
     *
     * @return array{all: int, statuses: array<string, int>} every status named, in the enum's order
     */
    public function statusCounts(Uuid $companyId, QuoteSearch $search): array;

    /** Null for a quote that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Quote;

    /** The same, its row held until the transaction this runs in ends; outside a transaction it refuses. */
    public function lockedOfIdInCompany(Uuid $id, Uuid $companyId): ?Quote;

    /**
     * The company's quotes holding any of these lines, in id order; with `$locked`, their rows held until the transaction
     * this runs in ends, in that order, so two requests holding the same quotes wait for each other rather than deadlock.
     *
     * @param list<Uuid> $lineIds
     *
     * @return list<Quote>
     */
    public function ofLineIdsInCompany(array $lineIds, Uuid $companyId, bool $locked): array;

    /** Whether a quote of the company already carries this number. */
    public function numberTaken(Uuid $companyId, string $number): bool;

    public function save(Quote $quote): void;
}
