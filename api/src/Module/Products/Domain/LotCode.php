<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

/**
 * What a lot or serial number is written as, wherever one is named: a stock lot, a delivery note line, an invoice line
 * (docs/SPEC.md § 7, 2026-09-22 11:10 and 2026-09-24 12:40 row 5): 1 to 40 visible ASCII characters, kept as typed. A
 * GS1 label's lot or serial (20 characters at most) always fits.
 */
final class LotCode
{
    public const int MAX = 40;
    private const string PATTERN = '/^[\x21-\x7E]{1,40}$/';

    public static function isWellFormed(string $code): bool
    {
        return 1 === preg_match(self::PATTERN, $code);
    }

    /** Whether a line of this product may name a lot or serial: only one of a product tracked by one. */
    public static function namedFor(?Product $product): bool
    {
        return null !== $product && ProductTracking::None !== $product->getTracking();
    }

    /**
     * The lot a copied line keeps (a credit note, an invoice drafted from delivery notes): the one its source named,
     * unless the product is no longer tracked, whose lines name none.
     */
    public static function carried(?Product $product, ?string $code): ?string
    {
        return self::namedFor($product) ? $code : null;
    }
}
