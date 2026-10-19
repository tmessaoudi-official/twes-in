<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fiscal\Domain\ValueObject\PrintedNames;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Quotes\Domain\Model\Quote;

/**
 * What issuing keeps of a document's names, for a test that issues one and never prints it: its units named in that
 * language and no tax, which a print would name by code. A test that prints keeps the company's taxes as well.
 */
final class KeptNames
{
    public static function of(Invoice|DeliveryNote|Quote $document, string $language = 'fr'): PrintedNames
    {
        $units = [];
        foreach ($document->getLines() as $line) {
            $units[] = $line->getUnit();
        }

        return PrintedNames::in($language, $units, []);
    }
}
