<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\FacturX;

/**
 * The seller (BG-4) or the buyer (BG-7): its name (BT-27, BT-44), its SIREN as legal registration (BT-30, BT-47,
 * scheme 0002), its VAT number (BT-31, BT-48) and its postal address (BG-5, BG-8).
 */
final readonly class CiiParty
{
    public function __construct(
        public string $name,
        public ?string $legalId,
        public ?string $vatId,
        public ?string $line1,
        public ?string $line2,
        public ?string $postcode,
        public ?string $city,
        public string $countryCode,
    ) {
    }
}
