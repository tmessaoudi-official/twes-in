<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

/**
 * What a company's documents say about it: legal identity, registration numbers, address, contact, banking, VAT regime
 * and the texts printed at the foot of an invoice (docs/SPEC.md § 4 company). Values are kept without stray spaces and
 * an empty value is no value, so the same profile typed twice compares equal. Which identifiers and regimes a company
 * may carry is its fiscal preset's to say, and is checked by the use case that revises the profile.
 */
final readonly class CompanyProfile
{
    public const string STANDARD_REGIME = 'standard';

    public ?string $legalName;
    public ?string $legalForm;
    /** @var array<string, string> by identifier key, in key order */
    public array $identifiers;
    public ?string $addressLine1;
    public ?string $addressLine2;
    public ?string $postalCode;
    public ?string $city;
    public ?string $email;
    public ?string $phone;
    public ?string $website;
    /** Without spaces, in capitals. */
    public ?string $iban;
    public ?string $bic;
    public string $vatRegime;
    public ?string $invoiceFooterText;
    public ?string $latePenaltyText;

    /** @param array<string, string|null> $identifiers */
    public function __construct(
        ?string $legalName = null,
        ?string $legalForm = null,
        array $identifiers = [],
        ?string $addressLine1 = null,
        ?string $addressLine2 = null,
        ?string $postalCode = null,
        ?string $city = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $website = null,
        ?string $iban = null,
        ?string $bic = null,
        string $vatRegime = self::STANDARD_REGIME,
        ?string $invoiceFooterText = null,
        ?string $latePenaltyText = null,
    ) {
        $this->legalName = self::text($legalName);
        $this->legalForm = self::text($legalForm);
        $kept = [];
        foreach ($identifiers as $key => $value) {
            $value = self::text($value);
            if (null !== $value) {
                $kept[(string) $key] = $value;
            }
        }
        ksort($kept, \SORT_STRING);
        $this->identifiers = $kept;
        $this->addressLine1 = self::text($addressLine1);
        $this->addressLine2 = self::text($addressLine2);
        $this->postalCode = self::text($postalCode);
        $this->city = self::text($city);
        $this->email = self::text($email);
        $this->phone = self::text($phone);
        $this->website = self::text($website);
        $this->iban = self::code($iban);
        $this->bic = self::code($bic);
        $this->vatRegime = trim($vatRegime);
        $this->invoiceFooterText = self::text($invoiceFooterText);
        $this->latePenaltyText = self::text($latePenaltyText);
    }

    public function equals(self $other): bool
    {
        return get_object_vars($this) === get_object_vars($other);
    }

    /** @return list<string> the fields whose value differs from the other profile's */
    public function differencesFrom(self $other): array
    {
        $theirs = get_object_vars($other);

        return array_keys(array_filter(get_object_vars($this), static fn (mixed $value, string $field): bool => $value !== $theirs[$field], \ARRAY_FILTER_USE_BOTH));
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
