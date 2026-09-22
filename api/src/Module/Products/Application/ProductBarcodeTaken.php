<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

/**
 * docs/SPEC.md § 7, 2026-09-17 and 2026-09-22 11:05: a code is unique within the company, every role together, so a
 * scan finds exactly one product — which is what lets the scanner add a line without asking which one was meant.
 *
 * It carries the reference of the product already holding the code, because "this barcode is taken" without
 * saying by what leaves the person to search for it by hand, and the row of the list it refuses.
 */
final class ProductBarcodeTaken extends \RuntimeException
{
    public function __construct(public readonly string $heldBy, public readonly string $barcode = '', public readonly int $index = 0)
    {
        parent::__construct(\sprintf('barcodes.%d.code: %s is already a code of %s.', $index, $barcode, $heldBy));
    }
}
