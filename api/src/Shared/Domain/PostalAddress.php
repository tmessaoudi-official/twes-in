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
 * without stray spaces, an empty part is no part, so an address typed twice compares equal. How long each part may be
 * and whether the country exists is `refusal()`'s to say, asked by whoever writes an address, never when one is read
 * back: an address stored before a rule still reads.
 */
#[ORM\Embeddable]
final class PostalAddress
{
    public const int LINE_MAX = 200;
    public const int POSTAL_CODE_MAX = 20;
    public const int CITY_MAX = 120;

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

    /** @return array{0: 'line1'|'line2'|'postalCode'|'city'|'countryCode', 1: Refusal}|null the first part refused */
    public function refusal(): ?array
    {
        foreach (['line1' => [$this->line1, self::LINE_MAX], 'line2' => [$this->line2, self::LINE_MAX], 'postalCode' => [$this->postalCode, self::POSTAL_CODE_MAX], 'city' => [$this->city, self::CITY_MAX]] as $part => [$value, $max]) {
            $refused = null === $value ? null : ContactRules::text($value, $max);
            if (null !== $refused) {
                return [$part, $refused];
            }
        }
        $refused = null === $this->countryCode ? null : ContactRules::country($this->countryCode);

        return null === $refused ? null : ['countryCode', $refused];
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
