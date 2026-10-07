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
     * and its discount, the invoice's own document taxes added as any draft's are, and the quote's issued deposits given
     * back. A deposit still a draft refuses it: issued or cancelled first, it is not left out unseen.
     *
     * @return Uuid the draft's id
     *
     * @throws QuoteInvoicingRefused when the invoice refuses what the quote says
     */
    public function draftFrom(Quote $quote, ?Uuid $actorUserId): Uuid;

    /**
     * A new draft deposit invoice (facture d'acompte) for a share of the quote, a percentage or an amount tax included:
     * one line per set of taxes the quote's lines carry, each that share of what those lines come to after the quote's
     * discount, so the quote's deposits never go beyond it.
     *
     * @return Uuid the draft's id
     *
     * @throws QuoteInvoicingRefused on `depositPercentage` or `depositAmount` when the share leaves the quote short
     */
    public function depositFrom(Quote $quote, ?string $percentage, ?string $amount, ?Uuid $actorUserId): Uuid;

    /**
     * The deposit invoices drawn from these quotes of one company, cancelled drafts included, the oldest first.
     *
     * @param list<Uuid> $quoteIds
     *
     * @return array<string, list<QuoteDeposit>> by quote id; a quote with none is absent
     */
    public function depositsOf(Company $company, array $quoteIds): array;

    /** Whether the invoice still stands: not cancelled, and still the company's. */
    public function stands(Company $company, Uuid $invoiceId): bool;
}
