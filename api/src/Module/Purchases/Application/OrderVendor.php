<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

use Symfony\Component\Uid\Uuid;

/**
 * A vendor as an order needs it: who it is, whether a new order may still name it, and what the order keeps of it
 * once sent, its legal name, identifiers, address and ways to reach it.
 */
final readonly class OrderVendor
{
    /** @param array<string, string> $identifiers the vendor's legal identifiers by scheme */
    public function __construct(
        public Uuid $id,
        public string $number,
        public string $name,
        public bool $active,
        public ?string $legalName = null,
        public array $identifiers = [],
        public ?string $line1 = null,
        public ?string $line2 = null,
        public ?string $postalCode = null,
        public ?string $city = null,
        public ?string $countryCode = null,
        public ?string $email = null,
        public ?string $phone = null,
    ) {
    }
}
