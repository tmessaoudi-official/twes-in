<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Application;

use App\Module\Vendors\Domain\VendorProfile;

/** A vendor as it is written: its number, its profile and whether it is still bought from. */
final readonly class VendorInput
{
    public function __construct(
        public string $number,
        public VendorProfile $profile,
        public bool $isActive,
    ) {
    }
}
