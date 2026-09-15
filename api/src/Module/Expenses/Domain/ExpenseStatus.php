<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain;

/** Where an expense stands (docs/SPEC.md § 4 expense): drafted, recorded in the books, then paid. It never goes back. */
enum ExpenseStatus: string
{
    case Draft = 'draft';
    case Recorded = 'recorded';
    case Paid = 'paid';
}
