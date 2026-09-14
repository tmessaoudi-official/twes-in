<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

use App\Module\Customers\Domain\Customer;
use App\Shared\Domain\PostalAddress;

/**
 * What a customer was called, where it was billed and under which tax regime, the day a note to it was validated
 * (docs/SPEC.md § 4 delivery_note.customer_snapshot): a customer renamed or moved later changes no validated note.
 */
final readonly class CustomerSnapshot
{
    /** @param array<string, string> $identifiers by identifier key */
    public function __construct(
        public string $number,
        public string $kind,
        public string $name,
        public ?string $legalName,
        public array $identifiers,
        public PostalAddress $billingAddress,
        public string $taxRegimeCode,
        public ?string $taxMentionKey,
    ) {
    }

    public static function of(Customer $customer): self
    {
        $profile = $customer->getProfile();
        $regime = $customer->getTaxRegime();

        return new self($customer->getNumber(), $profile->kind->value, $profile->name, $profile->legalName, $profile->identifiers, new PostalAddress(...$profile->billingAddress->parts()), $regime->getCode(), $regime->getMentionKey());
    }

    /** @return array<string, mixed> as stored */
    public function toArray(): array
    {
        [$line1, $line2, $postalCode, $city, $countryCode] = $this->billingAddress->parts();

        return [
            'number' => $this->number,
            'kind' => $this->kind,
            'name' => $this->name,
            'legalName' => $this->legalName,
            'identifiers' => $this->identifiers,
            'billingAddress' => ['line1' => $line1, 'line2' => $line2, 'postalCode' => $postalCode, 'city' => $city, 'countryCode' => $countryCode],
            'taxRegimeCode' => $this->taxRegimeCode,
            'taxMentionKey' => $this->taxMentionKey,
        ];
    }

    /** @param array<array-key, mixed> $stored what toArray() wrote */
    public static function fromArray(array $stored): self
    {
        $address = \is_array($stored['billingAddress'] ?? null) ? $stored['billingAddress'] : [];
        $identifiers = [];
        foreach (\is_array($stored['identifiers'] ?? null) ? $stored['identifiers'] : [] as $key => $value) {
            if (\is_string($value)) {
                $identifiers[(string) $key] = $value;
            }
        }

        return new self(
            self::text($stored, 'number') ?? '',
            self::text($stored, 'kind') ?? '',
            self::text($stored, 'name') ?? '',
            self::text($stored, 'legalName'),
            $identifiers,
            new PostalAddress(self::text($address, 'line1'), self::text($address, 'line2'), self::text($address, 'postalCode'), self::text($address, 'city'), self::text($address, 'countryCode')),
            self::text($stored, 'taxRegimeCode') ?? '',
            self::text($stored, 'taxMentionKey'),
        );
    }

    /** @param array<array-key, mixed> $stored */
    private static function text(array $stored, string $key): ?string
    {
        $value = $stored[$key] ?? null;

        return \is_string($value) ? $value : null;
    }
}
