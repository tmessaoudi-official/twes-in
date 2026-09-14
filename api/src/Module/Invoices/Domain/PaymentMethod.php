<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

/** How a customer paid (docs/SPEC.md § 7, 2026-09-14). */
enum PaymentMethod: string
{
    case Transfer = 'transfer';
    case Cash = 'cash';
    case Check = 'check';
    case Card = 'card';
    case Other = 'other';
}
