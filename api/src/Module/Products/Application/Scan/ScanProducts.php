<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application\Scan;

use App\Module\Products\Domain\Repository\ProductRepository;
use App\Module\Products\Domain\ValueObject\Barcode;
use App\Module\Products\Domain\ValueObject\Gs1Scan;
use App\Tenancy\Domain\Model\Company;

/**
 * What one scan names (docs/SPEC.md § 7, 2026-09-20 04:15, 2026-09-22 11:05 and 11:10): the one product holding the
 * code, the code's role and how many pieces it enters, and what a GS1 scan carried besides. A scan names exactly one
 * product or none — the company's codes are unique on their key — which is what lets a screen act on it without
 * asking which was meant. A plain scan no barcode answers is tried as a reference, whole and as written (2026-10-22
 * 18:58): loose goods with no code carry their reference on their label, and a reference is unique in its company too.
 * A barcode is tried first, so a reference that reads like one never takes its place.
 */
final readonly class ScanProducts
{
    public function __construct(private ProductRepository $products)
    {
    }

    /** @return array{0: ScannedCode, 1: Gs1Scan}|null what the scan named and the scan as read, or null when nothing does */
    public function find(Company $company, string $scanned): ?array
    {
        $scan = Gs1Scan::read($scanned);
        $held = $this->products->barcodeOfKeyInCompany(Barcode::keyOf($scan->code()), $company->getId());
        if (null !== $held) {
            return [ScannedCode::barcode($held), $scan];
        }
        $product = $scan->isGs1() || '' === $scan->raw ? null : $this->products->ofReferenceInCompany($scan->raw, $company->getId());

        // An old label of a product merged away names the product kept, as its barcodes moved there with the merge.
        return null === $product ? null : [ScannedCode::reference($product->standingFor()), $scan];
    }
}
