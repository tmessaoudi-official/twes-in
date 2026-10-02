<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

/** What moved a customer's credit balance. */
enum CreditEntryKind: string
{
    /** Money received that no invoice took: it adds to the balance. */
    case Deposit = 'deposit';
    /** Credit paid into an invoice as a payment: it takes from the balance. */
    case Applied = 'applied';
}
