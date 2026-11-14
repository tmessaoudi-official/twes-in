<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Infrastructure\FiscalInventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;
use App\Fiscal\Application\Inventory\FiscalRecords;

/** The company's subscription to the platform and the payments it declared for it: the developer's own books. */
final readonly class LicensingFiscalRecords implements DeclaresFiscalRecords
{
    public function fiscalRecords(): array
    {
        return [
            FiscalRecords::kept('licensing.subscription', 'subscription'),
            FiscalRecords::kept('licensing.payment_declarations', 'payment_declaration', dateColumn: 'paid_on'),
        ];
    }

    public function notFiscal(): array
    {
        return [];
    }
}
