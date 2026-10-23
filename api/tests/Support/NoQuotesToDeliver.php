<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fiscal\Infrastructure\Currency\IntlCurrencyScales;
use App\Module\DeliveryNotes\Application\InvoicedLines;
use App\Module\DeliveryNotes\Application\Quotes\DeliverableQuotes;
use App\Module\DeliveryNotes\Application\Quotes\QuoteDiscounts;
use App\Module\DeliveryNotes\Application\Quotes\QuoteLines;
use App\Module\DeliveryNotes\Application\Quotes\QuoteToDeliver;
use App\Module\DeliveryNotes\Domain\Repository\DeliveryNoteRepository;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/** A company with no quote to deliver, for the unit tests of notes that name none. */
final readonly class NoQuotesToDeliver implements DeliverableQuotes
{
    public function ofId(Company $company, Uuid $quoteId, bool $locked): ?QuoteToDeliver
    {
        return null;
    }

    public function ofLines(Company $company, array $quoteLineIds, bool $locked): array
    {
        return [];
    }

    public static function lines(DeliveryNoteRepository $notes): QuoteLines
    {
        return new QuoteLines(new self(), $notes);
    }

    public static function discounts(DeliveryNoteRepository $notes, InvoicedLines $invoiced): QuoteDiscounts
    {
        return new QuoteDiscounts(new self(), $notes, $invoiced, new IntlCurrencyScales());
    }
}
