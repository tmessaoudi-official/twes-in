<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A postal address as documents print it: two lines, a postal code, a city and a country (ISO 3166-1 alpha-2). Kept
 * without stray spaces, an empty part is no part, so an address typed twice compares equal; whether the country
 * exists and how long each part may be is checked where the address is received.
 */
#[ORM\Embeddable]
final class PostalAddress
{
    #[ORM\Column(name: 'line1', length: 200, nullable: true)]
    public private(set) ?string $line1;

    #[ORM\Column(name: 'line2', length: 200, nullable: true)]
    public private(set) ?string $line2;

    #[ORM\Column(length: 20, nullable: true)]
    public private(set) ?string $postalCode;

    #[ORM\Column(length: 120, nullable: true)]
    public private(set) ?string $city;

    #[ORM\Column(length: 2, nullable: true)]
    public private(set) ?string $countryCode;

    public function __construct(?string $line1 = null, ?string $line2 = null, ?string $postalCode = null, ?string $city = null, ?string $countryCode = null)
    {
        $this->line1 = self::text($line1);
        $this->line2 = self::text($line2);
        $this->postalCode = self::text($postalCode);
        $this->city = self::text($city);
        $country = self::text($countryCode);
        $this->countryCode = null === $country ? null : strtoupper($country);
    }

    public function isEmpty(): bool
    {
        return [null, null, null, null, null] === $this->parts();
    }

    public function equals(self $other): bool
    {
        return $this->parts() === $other->parts();
    }

    /** @return list<string|null> */
    public function parts(): array
    {
        return [$this->line1, $this->line2, $this->postalCode, $this->city, $this->countryCode];
    }

    private static function text(?string $value): ?string
    {
        $value = null === $value ? '' : trim($value);

        return '' === $value ? null : $value;
    }
}
