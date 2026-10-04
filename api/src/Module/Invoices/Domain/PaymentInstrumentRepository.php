<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use Symfony\Component\Uid\Uuid;

/** The cheques and traites received (docs/SPEC.md § 7, 2026-09-21 18:40). */
interface PaymentInstrumentRepository
{
    public function save(PaymentInstrument $instrument): void;

    public function remove(PaymentInstrument $instrument): void;

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?PaymentInstrument;

    /** @return list<PaymentInstrument> an invoice's, by due day then in the order they were received */
    public function ofInvoice(Uuid $companyId, Uuid $invoiceId): array;

    /** Three decimals: what the invoice's held and deposited instruments promise. */
    public function openAmount(Uuid $companyId, Uuid $invoiceId): string;

    /** The instrument a payment cashed, if it cashed one. */
    public function cashedByPayment(Uuid $companyId, Uuid $paymentId): ?PaymentInstrument;
}
