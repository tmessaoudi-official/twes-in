<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application\Delivery;

use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The delivery notes an accepted quote is delivered by. A port this module owns, answered by the delivery notes', which
 * keep them, so neither calls into the other. Once one stands, the quote is invoiced through its notes and never also as
 * a whole, or its goods would leave stock and be billed twice.
 */
interface QuoteDeliveryNotes
{
    /**
     * What the notes that are not cancelled deliver of each quote, read once for a page of one company's quotes.
     *
     * @param array<string, list<Uuid>> $lineIdsByQuote each quote's line ids, by quote id
     *
     * @return array<string, QuoteDeliveries> by quote id; a quote no note delivers is absent
     */
    public function ofQuotes(Company $company, array $lineIdsByQuote): array;
}
