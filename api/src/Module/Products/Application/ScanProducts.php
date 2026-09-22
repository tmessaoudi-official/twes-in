<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Module\Products\Domain\Barcode;
use App\Module\Products\Domain\Gs1Scan;
use App\Module\Products\Domain\ProductBarcode;
use App\Module\Products\Domain\ProductRepository;
use App\Tenancy\Domain\Company;

/**
 * What one scan names (docs/SPEC.md § 7, 2026-09-20 04:15, 2026-09-22 11:05 and 11:10): the one product holding the
 * code, the code's role and how many pieces it enters, and what a GS1 scan carried besides. A scan names exactly one
 * product or none — the company's codes are unique on their key — which is what lets a screen act on it without
 * asking which was meant.
 */
final readonly class ScanProducts
{
    public function __construct(private ProductRepository $products)
    {
    }

    /** @return array{0: ProductBarcode, 1: Gs1Scan}|null the code found and the scan as read, or null when none holds it */
    public function find(Company $company, string $scanned): ?array
    {
        $scan = Gs1Scan::read($scanned);
        $held = $this->products->barcodeOfKeyInCompany(Barcode::keyOf($scan->code()), $company->getId());

        return null === $held ? null : [$held, $scan];
    }
}
