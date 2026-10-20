<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Import;

use App\ImportExport\Application\RowNotes;
use App\Module\Vendors\Domain\Model\Vendor;

/**
 * A file written before a merge names the vendor merged away by its number: the product is bought from the vendor
 * kept, and the row says so, as the screens show the vendor kept wherever the one merged away was.
 */
final class MergedVendors
{
    public static function followed(Vendor $named, string $column, RowNotes $notes): Vendor
    {
        $kept = $named->standingFor();
        if ($kept !== $named) {
            $notes->note($column, 'vendor_followed', ['number' => $named->getNumber(), 'kept' => $kept->getNumber()]);
        }

        return $kept;
    }
}
