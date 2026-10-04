<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

/** What a customer handed over in place of money: a cheque, usually dated ahead, or a bill of exchange (traite). */
enum InstrumentKind: string
{
    case Check = 'check';
    case Draft = 'draft';
}
