<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

/** What a person does at once to the invoices they selected on a list page. */
enum BulkAction: string
{
    case Export = 'export';
    case Pdf = 'pdf';
    /** Each invoice paid for what it still owes, by one method on one day. */
    case Pay = 'pay';
}
