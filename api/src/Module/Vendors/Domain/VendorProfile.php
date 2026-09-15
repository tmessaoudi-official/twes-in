<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Domain;

use App\Shared\Domain\PostalAddress;
use Symfony\Component\Uid\Uuid;

/**
 * What a company knows of a vendor (docs/SPEC.md § 4 vendor): names, registration numbers, contact, an address, the
 * bank account it is paid on, its payment terms and internal notes. Values are kept without stray spaces, bank codes
 * uppercase without any space, and an empty value is no value; which registration numbers a vendor may carry is its
 * company's preset's to say, checked by the use case.
 */
final readonly class VendorProfile
{
    public const int NAME_MAX = 200;
    public const int PAYMENT_TERMS_MAX = 365;

    public string $name;
    public ?string $legalName;
    /** @var array<string, string> by identifier key, in key order */
    public array $identifiers;
    public ?string $email;
    public ?string $phone;
    public ?string $website;
    public ?string $iban;
    public ?string $bic;
    /** Days after the vendor's invoice its payment is due, from 0 to a year; null when the vendor says nothing. */
    public ?int $paymentTermsDays;
    public ?string $notes;

    /**
     * @param array<string, string|null> $identifiers
     *
     * @throws InvalidVendor
     */
    public function __construct(
        string $name,
        ?string $legalName = null,
        array $identifiers = [],
        ?string $email = null,
        ?string $phone = null,
        ?string $website = null,
        public PostalAddress $address = new PostalAddress(),
        ?string $iban = null,
        ?string $bic = null,
        ?int $paymentTermsDays = null,
        ?string $notes = null,
        /** The expense category its expenses usually go to; checked against the company by the use case. */
        public ?Uuid $defaultExpenseCategoryId = null,
    ) {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidVendor('name', \sprintf('A vendor is named in 1 to %d characters.', self::NAME_MAX));
        }
        $this->name = $name;
        $this->legalName = self::text($legalName);
        $kept = [];
        foreach ($identifiers as $key => $value) {
            $value = self::text($value);
            if (null !== $value) {
                $kept[(string) $key] = $value;
            }
        }
        ksort($kept, \SORT_STRING);
        $this->identifiers = $kept;
        $this->email = self::text($email);
        $this->phone = self::text($phone);
        $this->website = self::text($website);
        $this->iban = self::code($iban);
        $this->bic = self::code($bic);
        if (null !== $paymentTermsDays && ($paymentTermsDays < 0 || $paymentTermsDays > self::PAYMENT_TERMS_MAX)) {
            throw new InvalidVendor('paymentTermsDays', \sprintf('Payment terms run from 0 to %d days.', self::PAYMENT_TERMS_MAX));
        }
        $this->paymentTermsDays = $paymentTermsDays;
        $this->notes = self::text($notes);
    }

    /** @return list<string> the fields whose value differs from the other profile's, in declaration order */
    public function differencesFrom(self $other): array
    {
        $theirs = $other->comparable();

        return array_keys(array_filter($this->comparable(), static fn (mixed $value, string $field): bool => $value !== $theirs[$field], \ARRAY_FILTER_USE_BOTH));
    }

    /** @return array<string, mixed> */
    private function comparable(): array
    {
        return [
            'name' => $this->name,
            'legalName' => $this->legalName,
            'identifiers' => $this->identifiers,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'address' => $this->address->parts(),
            'iban' => $this->iban,
            'bic' => $this->bic,
            'paymentTermsDays' => $this->paymentTermsDays,
            'notes' => $this->notes,
            'defaultExpenseCategoryId' => $this->defaultExpenseCategoryId?->toRfc4122(),
        ];
    }

    private static function text(?string $value): ?string
    {
        $value = null === $value ? '' : trim($value);

        return '' === $value ? null : $value;
    }

    private static function code(?string $value): ?string
    {
        $value = strtoupper((string) preg_replace('/\s+/', '', (string) $value));

        return '' === $value ? null : $value;
    }
}
