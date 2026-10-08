<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\AccountingExport\Infrastructure\Module;

/** Exporting the books: the four files an accountant takes, which reading invoices or expenses alone does not give. */
final class AccountingExportPermission
{
    public const string EXPORT = 'accounting.export';
}
