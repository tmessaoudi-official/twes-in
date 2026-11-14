<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\FiscalInventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;
use App\Fiscal\Application\Inventory\FiscalRecords;

/** The delivery notes numbered at validation, cancelled ones included, with their lines and stored PDFs. */
final readonly class DeliveryNoteFiscalRecords implements DeclaresFiscalRecords
{
    public function fiscalRecords(): array
    {
        return [
            FiscalRecords::fiscal('delivery_notes.numbered', 'delivery_note', 'number IS NOT NULL', ['delivery_note_line', 'delivery_note_line_tax'], pdfColumn: 'pdf_file_id', numberColumn: 'number', dateColumn: 'issue_date'),
        ];
    }

    public function notFiscal(): array
    {
        return [];
    }
}
