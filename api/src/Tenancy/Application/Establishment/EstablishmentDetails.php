<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Establishment;

/** What a person writes about an establishment, when adding it and each time they revise it. */
final readonly class EstablishmentDetails
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $addressLine1,
        public ?string $addressLine2,
        public ?string $postalCode,
        public ?string $city,
        public ?string $phone,
        public ?string $email,
        public bool $isDefault,
    ) {
    }
}
