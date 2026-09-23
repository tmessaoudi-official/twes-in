<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Scanning\Infrastructure\Http;

/**
 * Where a phone opens a pairing link, when that is not where the computer tab is (docs/SPEC.md § 7, 2026-09-23 14:08):
 * a tab on http://localhost shows a link no phone can follow, so a development stack names its HTTPS address on the
 * local network (PAIRING_ORIGIN, set by `make up`). Only an origin counts; anything else is ignored, and the tab then
 * builds the link on its own address.
 */
final class PhoneAddress
{
    private const string ORIGIN = '#^https?://[A-Za-z0-9.\-\[\]:]+$#';

    public static function of(string $configured): ?string
    {
        $origin = rtrim(trim($configured), '/');

        return 1 === preg_match(self::ORIGIN, $origin) ? $origin : null;
    }
}
