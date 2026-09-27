<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use App\Shared\Domain\PostalAddress;

/**
 * The seller as an issued document prints it, taken when the document is issued: a company that renames, moves, changes
 * bank or currency later changes no document it already issued. The address and the contacts are the establishment's
 * where it has them, else the company's, as the document prints them.
 */
final readonly class SellerSnapshot
{
    /** @param array<string, string> $identifiers by identifier key */
    public function __construct(
        public string $name,
        public ?string $legalForm,
        public array $identifiers,
        public PostalAddress $address,
        public ?string $phone,
        public ?string $email,
        public ?string $iban,
        public ?string $bic,
        public string $currency,
        /** The company's VAT regime, which names the category of a line issued without VAT. */
        public string $vatRegime,
    ) {
    }

    public static function of(Company $company, Establishment $establishment): self
    {
        $profile = $company->getProfile();
        $address = null !== $establishment->getAddressLine1()
            ? new PostalAddress($establishment->getAddressLine1(), $establishment->getAddressLine2(), $establishment->getPostalCode(), $establishment->getCity(), $company->getCountryCode())
            : new PostalAddress($profile->addressLine1, $profile->addressLine2, $profile->postalCode, $profile->city, $company->getCountryCode());

        return new self(
            $profile->legalName ?? $company->getName(),
            $profile->legalForm,
            $profile->identifiers,
            $address,
            $establishment->getPhone() ?? $profile->phone,
            $establishment->getEmail() ?? $profile->email,
            $profile->iban,
            null === $profile->iban ? null : $profile->bic,
            $company->getCurrency(),
            $profile->vatRegime,
        );
    }

    /** @return array<string, mixed> as stored */
    public function toArray(): array
    {
        [$line1, $line2, $postalCode, $city, $countryCode] = $this->address->parts();

        return [
            'name' => $this->name,
            'legalForm' => $this->legalForm,
            'identifiers' => $this->identifiers,
            'address' => ['line1' => $line1, 'line2' => $line2, 'postalCode' => $postalCode, 'city' => $city, 'countryCode' => $countryCode],
            'phone' => $this->phone,
            'email' => $this->email,
            'iban' => $this->iban,
            'bic' => $this->bic,
            'currency' => $this->currency,
            'vatRegime' => $this->vatRegime,
        ];
    }

    /** @param array<array-key, mixed> $stored what toArray() wrote */
    public static function fromArray(array $stored): self
    {
        $address = \is_array($stored['address'] ?? null) ? $stored['address'] : [];
        $identifiers = [];
        foreach (\is_array($stored['identifiers'] ?? null) ? $stored['identifiers'] : [] as $key => $value) {
            if (\is_string($value)) {
                $identifiers[(string) $key] = $value;
            }
        }

        return new self(
            self::text($stored, 'name') ?? '',
            self::text($stored, 'legalForm'),
            $identifiers,
            new PostalAddress(self::text($address, 'line1'), self::text($address, 'line2'), self::text($address, 'postalCode'), self::text($address, 'city'), self::text($address, 'countryCode')),
            self::text($stored, 'phone'),
            self::text($stored, 'email'),
            self::text($stored, 'iban'),
            self::text($stored, 'bic'),
            self::text($stored, 'currency') ?? '',
            self::text($stored, 'vatRegime') ?? CompanyProfile::STANDARD_REGIME,
        );
    }

    /** @param array<array-key, mixed> $stored */
    private static function text(array $stored, string $key): ?string
    {
        return \is_string($stored[$key] ?? null) ? $stored[$key] : null;
    }
}
