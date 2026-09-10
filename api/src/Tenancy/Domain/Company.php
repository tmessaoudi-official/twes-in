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
 * The tenant. G1a carries the identity columns; the profile (identifiers, address, banking, fiscal
 * preset, terms) lands with the company profile at G3 as additive migrations.
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
