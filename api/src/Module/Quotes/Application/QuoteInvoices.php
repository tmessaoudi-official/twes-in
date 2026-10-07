<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

use App\Module\Quotes\Domain\Quote;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The invoices an accepted quote is drafted into. A port this module owns, answered by the invoices', which keep the
 * drafts, so neither calls into the other. Both run inside the caller's transaction.
 */
interface QuoteInvoices
{
    /**
     * A new draft invoice of the quote's customer and establishment carrying its lines, its reference, its printed notes
     * and its discount, the invoice's own document taxes added as any draft's are.
     *
     * @return Uuid the draft's id
     *
     * @throws QuoteInvoicingRefused when the invoice refuses what the quote says
     */
    public function draftFrom(Quote $quote, ?Uuid $actorUserId): Uuid;

    /** Whether the invoice still stands: not cancelled, and still the company's. */
    public function stands(Company $company, Uuid $invoiceId): bool;
}
