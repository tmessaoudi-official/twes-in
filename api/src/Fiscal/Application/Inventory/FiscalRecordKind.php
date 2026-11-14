<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Inventory;

/**
 * Whether records make a company's data fiscal, so that a wipe refuses without its acknowledgement: an issued document
 * or a record of its ledger is; what the shop or the law keeps beside them (the journal, the imports, the legal pages'
 * versions) is listed and carried into the kept-aside export, but is no issued document.
 */
enum FiscalRecordKind: string
{
    case Fiscal = 'fiscal';
    case Kept = 'kept';
}
