<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application\Quotes;

use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The accepted quotes delivery notes deliver, as notes see them. A port this module owns, answered by the quotes', which
 * keep them, so neither calls into the other. A locked read holds the quote's row until the caller's transaction ends:
 * every write that decides what is left of a quote holds it, so two notes drafted at once never take more than it.
 */
interface DeliverableQuotes
{
    /** The company's quote of this id, locked when `$locked`, or null when it has none. */
    public function ofId(Company $company, Uuid $quoteId, bool $locked): ?QuoteToDeliver;

    /**
     * The company's quotes holding any of these lines, in id order, locked when `$locked`.
     *
     * @param list<Uuid> $quoteLineIds
     *
     * @return list<QuoteToDeliver>
     */
    public function ofLines(Company $company, array $quoteLineIds, bool $locked): array;
}
