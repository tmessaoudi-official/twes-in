<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Inventory;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A context's part of the fiscal inventory, declared in its own `Infrastructure/FiscalInventory/`: which of its tables
 * hold issued documents, records of their ledger or what is kept beside them, and which of its tables that look as if
 * they might (a status, a number, a link to a counted table) hold none, and why.
 */
#[AutoconfigureTag('app.fiscal.records')]
interface DeclaresFiscalRecords
{
    /** @return list<FiscalRecords> */
    public function fiscalRecords(): array;

    /** @return array<string, string> a table of the context's that holds no fiscal record, and why */
    public function notFiscal(): array;
}
