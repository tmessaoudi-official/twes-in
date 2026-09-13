<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The tenant. G1a carries the identity columns and G3a the fiscal preset; the profile (identifiers, address,
 * banking, terms) lands with the company profile as additive migrations.
 */
#[ORM\Entity]
#[ORM\Table(name: 'company')]
class Company
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_SUSPENDED = 'suspended';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 2)]
    private string $countryCode;

    /**
     * The fiscal preset (api/config/fiscal/<key>.yaml) the company's taxes were copied from: its country's. A column
     * of its own because a country may come to carry several presets, a free zone or an offshore regime.
     */
    #[ORM\Column(length: 8)]
    private string $fiscalPreset;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 5)]
    private string $locale;

    #[ORM\Column(length: 64)]
    private string $timezone;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_ACTIVE;

    /**
     * Whether every member of this company must carry a second factor. A column rather than the first row of
     * a settings table: settings are G3, where the real requirements live, and moving this one field there
     * is a data migration (ruling of 2026-09-10).
     */
    #[ORM\Column]
    private bool $mfaRequired = false;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $legalName = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $legalForm = null;

    /** @var array<string, string> registration numbers by the preset's identifier key */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $identifiers = [];

    #[ORM\Column(name: 'address_line1', length: 200, nullable: true)]
    private ?string $addressLine1 = null;

    #[ORM\Column(name: 'address_line2', length: 200, nullable: true)]
    private ?string $addressLine2 = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 254, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban = null;

    #[ORM\Column(length: 11, nullable: true)]
    private ?string $bic = null;

    /**
     * A literal, not CompanyProfile::STANDARD_REGIME: a property default naming another class's constant leaves the
     * class's defaults to be resolved at runtime, which Doctrine's lazy ghosts skip (PHP 8.5 asserts on it in
     * zend_lazy_object_init). CompanyProfileTest pins the two to the same value.
     */
    #[ORM\Column(length: 32, options: ['default' => 'standard'])]
    private string $vatRegime = 'standard';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $invoiceFooterText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $latePenaltyText = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $countryCode, string $currency, string $locale, string $timezone, ?\DateTimeImmutable $now = null)
    {
        $now ??= new \DateTimeImmutable();
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->countryCode = strtoupper($countryCode);
        $this->fiscalPreset = $this->countryCode;
        $this->currency = strtoupper($currency);
        $this->locale = $locale;
        $this->timezone = $timezone;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * A company an operator creates has no owner yet, so nobody can sign into it: it waits, and its first
     * owner accepting the invitation activates it (docs/SPEC.md § 7, 2026-09-09).
     */
    public static function pending(string $name, string $countryCode, string $currency, string $locale, string $timezone, ?\DateTimeImmutable $now = null): self
    {
        $company = new self($name, $countryCode, $currency, $locale, $timezone, $now);
        $company->status = self::STATUS_PENDING;

        return $company;
    }

    /** Idempotent: a company that is already active stays active, and its timestamp does not move. */
    public function activate(?\DateTimeImmutable $now = null): void
    {
        $this->changeStatusTo(self::STATUS_ACTIVE, $now);
    }

    public function suspend(?\DateTimeImmutable $now = null): void
    {
        $this->changeStatusTo(self::STATUS_SUSPENDED, $now);
    }

    private function changeStatusTo(string $status, ?\DateTimeImmutable $now): void
    {
        if ($status === $this->status) {
            return;
        }
        $this->status = $status;
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    public function getFiscalPreset(): string
    {
        return $this->fiscalPreset;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return self::STATUS_ACTIVE === $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getProfile(): CompanyProfile
    {
        return new CompanyProfile(
            $this->legalName,
            $this->legalForm,
            $this->identifiers,
            $this->addressLine1,
            $this->addressLine2,
            $this->postalCode,
            $this->city,
            $this->email,
            $this->phone,
            $this->website,
            $this->iban,
            $this->bic,
            $this->vatRegime,
            $this->invoiceFooterText,
            $this->latePenaltyText,
        );
    }

    /** @return bool whether this changed anything, so a caller records a revision only when there was one */
    public function reviseProfile(CompanyProfile $profile, ?\DateTimeImmutable $now = null): bool
    {
        if ($this->getProfile()->equals($profile)) {
            return false;
        }

        $this->legalName = $profile->legalName;
        $this->legalForm = $profile->legalForm;
        $this->identifiers = $profile->identifiers;
        $this->addressLine1 = $profile->addressLine1;
        $this->addressLine2 = $profile->addressLine2;
        $this->postalCode = $profile->postalCode;
        $this->city = $profile->city;
        $this->email = $profile->email;
        $this->phone = $profile->phone;
        $this->website = $profile->website;
        $this->iban = $profile->iban;
        $this->bic = $profile->bic;
        $this->vatRegime = $profile->vatRegime;
        $this->invoiceFooterText = $profile->invoiceFooterText;
        $this->latePenaltyText = $profile->latePenaltyText;
        $this->updatedAt = $now ?? new \DateTimeImmutable();

        return true;
    }

    public function isMfaRequired(): bool
    {
        return $this->mfaRequired;
    }

    /** @return bool whether this changed anything, so a caller can stay quiet when it did not */
    public function requireMfa(bool $required, ?\DateTimeImmutable $now = null): bool
    {
        if ($this->mfaRequired === $required) {
            return false;
        }

        $this->mfaRequired = $required;
        $this->updatedAt = $now ?? new \DateTimeImmutable();

        return true;
    }
}
