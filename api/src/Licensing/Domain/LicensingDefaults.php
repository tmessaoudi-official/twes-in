<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Domain;

/** The platform's defaults for what a subscription's own terms leave open. Operator-owned platform settings. */
final readonly class LicensingDefaults
{
    public function __construct(
        public int $graceDays,
        public UnpaidMode $unpaidMode,
    ) {
    }
}
