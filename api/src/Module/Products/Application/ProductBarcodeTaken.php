<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

/**
 * docs/SPEC.md § 7, 2026-09-17: a barcode is unique within the company when set, so a scan finds exactly one
 * product — which is what lets the scanner add a line without asking which one was meant.
 *
 * It carries the reference of the product already holding the code, because "this barcode is taken" without
 * saying by what leaves the person to search for it by hand.
 */
final class ProductBarcodeTaken extends \RuntimeException
{
    public function __construct(public readonly string $heldBy)
    {
        parent::__construct(\sprintf('Another product of this company already has this barcode: %s.', $heldBy));
    }
}
