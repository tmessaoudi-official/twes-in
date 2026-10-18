<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Domain\ValueObject;

/**
 * What the vendor was called and where it was, the day the order was sent: what the order prints from then on, whatever
 * the vendor's card says later.
 */
final readonly class VendorSnapshot
{
    /** @param array<string, string> $identifiers the vendor's legal identifiers by scheme, such as its tax number */
    public function __construct(
        public string $number,
        public string $name,
        public ?string $legalName,
        public array $identifiers,
        public ?string $line1,
        public ?string $line2,
        public ?string $postalCode,
        public ?string $city,
        public ?string $countryCode,
        public ?string $email,
        public ?string $phone,
    ) {
    }

    /** @return array{number: string, name: string, legalName: string|null, identifiers: array<string, string>, line1: string|null, line2: string|null, postalCode: string|null, city: string|null, countryCode: string|null, email: string|null, phone: string|null} */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'name' => $this->name,
            'legalName' => $this->legalName,
            'identifiers' => $this->identifiers,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'postalCode' => $this->postalCode,
            'city' => $this->city,
            'countryCode' => $this->countryCode,
            'email' => $this->email,
            'phone' => $this->phone,
        ];
    }

    /** @param array<string, mixed> $stored what toArray() wrote */
    public static function fromArray(array $stored): self
    {
        $text = static fn (string $key): ?string => \is_string($stored[$key] ?? null) ? $stored[$key] : null;
        $identifiers = [];
        foreach (\is_array($stored['identifiers'] ?? null) ? $stored['identifiers'] : [] as $scheme => $value) {
            if (\is_string($scheme) && \is_string($value)) {
                $identifiers[$scheme] = $value;
            }
        }

        return new self($text('number') ?? '', $text('name') ?? '', $text('legalName'), $identifiers, $text('line1'), $text('line2'), $text('postalCode'), $text('city'), $text('countryCode'), $text('email'), $text('phone'));
    }
}
