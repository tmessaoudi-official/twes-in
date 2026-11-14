<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Infrastructure\FiscalInventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;
use App\Fiscal\Application\Inventory\FiscalRecords;

/** The files attached to records, an expense's receipt among them: carried with the documents they support. */
final readonly class AttachmentFiscalRecords implements DeclaresFiscalRecords
{
    public function fiscalRecords(): array
    {
        return [
            FiscalRecords::kept('files.attachments', 'attachment', 'removed_at IS NULL', dateColumn: 'created_at'),
        ];
    }

    public function notFiscal(): array
    {
        return [];
    }
}
