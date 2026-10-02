<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

/** Where the part of a credit note that was already paid goes: kept for the customer, or paid back to them. */
enum CreditExcessTo: string
{
    case Balance = 'balance';
    case Refund = 'refund';
}
