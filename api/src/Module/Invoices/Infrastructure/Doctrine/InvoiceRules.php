<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Doctrine;

use App\Module\Invoices\Domain\ValueObject\InvoiceStatus;

/**
 * What a statement means by an issued invoice and an open one, written once for every figure, list, report and watch
 * that reads them, on the alias `i`; each statement adds its company and its document types. `InvoiceRulesTest` refuses
 * a copy written by hand.
 */
final class InvoiceRules
{
    /** Issued and standing: issuing writes the amount due, which no draft has, and a cancelled one was a withdrawn draft. Binds `:cancelled`. */
    public const string ISSUED = 'i.status <> :cancelled AND i.amount_due IS NOT NULL';

    /** Open: in an open status with something left to pay, as an invoice issued with nothing to pay waits for nothing. Binds `:open`. */
    public const string OPEN = 'i.status IN (:open) AND i.amount_due > 0';

    /** `OPEN` in Doctrine's query language, for the list and what reads through its search. Binds `:open`. */
    public const string OPEN_DQL = 'i.status IN (:open) AND i.amountDue > 0';

    /** What `:open` binds, as `ArrayParameterType::STRING`. */
    public const array OPEN_STATUSES = [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value];

    /** What `:cancelled` binds. */
    public const string CANCELLED = InvoiceStatus::Cancelled->value;
}
