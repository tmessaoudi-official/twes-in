<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use Symfony\Component\Uid\Uuid;

/** The reminder stages late invoices reached. */
interface InvoiceReminderRepository
{
    /**
     * Records the stage unless that invoice already reached it, and says whether it did: two runs at once both try,
     * one records it, so the stage is told once.
     */
    public function recordOnce(InvoiceReminder $reminder): bool;

    /**
     * The highest stage each of these invoices reached so far; an invoice that reached none is left out.
     *
     * @param list<Uuid> $invoiceIds
     *
     * @return array<string, int> by invoice id
     */
    public function highestStages(Uuid $companyId, array $invoiceIds): array;

    /** @return list<InvoiceReminder> the invoice's stages, the first first */
    public function ofInvoice(Uuid $companyId, Uuid $invoiceId): array;

    /** Names the draft of the late fee the invoice's stage charged. */
    public function recordLateFee(Uuid $companyId, Uuid $invoiceId, int $stage, Uuid $feeInvoiceId): void;

    /**
     * Which of these invoices are themselves late fees a stage drafted: a fee's own lateness is reminded, never charged.
     *
     * @param list<Uuid> $invoiceIds
     *
     * @return list<string> their ids
     */
    public function lateFeesAmong(Uuid $companyId, array $invoiceIds): array;
}
