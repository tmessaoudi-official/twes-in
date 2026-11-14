<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\FiscalInventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;

/** A quote is an offer, numbered but no accounting document; the invoice it became is counted as an invoice. */
final readonly class QuoteFiscalRecords implements DeclaresFiscalRecords
{
    public function fiscalRecords(): array
    {
        return [];
    }

    public function notFiscal(): array
    {
        return [
            'quote' => 'an offer, numbered but no accounting document; the invoice it became is counted as an invoice',
        ];
    }
}
