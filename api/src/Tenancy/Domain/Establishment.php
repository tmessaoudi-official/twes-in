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
 * A place a company issues documents from (docs/SPEC.md § 4). A company starts with one, its default; its code is
 * the establishment part of a registration number (the three digits closing a Tunisian matricule fiscal, the NIC of
 * a French SIRET), so its shape is the fiscal preset's to check. Documents and numbering series name the issuing
 * establishment; exactly one establishment of a company is its default.
 */
#[ORM\Entity]
#[ORM\Table(name: 'establishment')]
#[ORM\Index(name: 'idx_establishment_company', columns: ['company_id'])]
#[ORM\UniqueConstraint(name: 'uniq_establishment_company_code', columns: ['company_id', 'code'])]
#[ORM\UniqueConstraint(name: 'uniq_establishment_default', columns: ['company_id'], options: ['where' => 'is_default'])]
class Establishment
{
    public const string CODE = '/^[A-Za-z0-9]{1,16}$/';
    private const int NAME_MAX = 120;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 16)]
    private string $code;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    #[ORM\Column(name: 'address_line1', length: 200, nullable: true)]
    private ?string $addressLine1 = null;

    #[ORM\Column(name: 'address_line2', length: 200, nullable: true)]
    private ?string $addressLine2 = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 254, nullable: true)]
    private ?string $email = null;

    #[ORM\Column]
    private bool $isDefault = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Company $company, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** @throws InvalidEstablishment */
    public static function create(Company $company, string $code, string $name, bool $isDefault, \DateTimeImmutable $now): self
    {
        $establishment = new self($company, $now);
        $establishment->code = self::code($code);
        $establishment->name = self::name($name);
        $establishment->isDefault = $isDefault;

        return $establishment;
    }

    /**
     * @return bool whether anything changed
     *
     * @throws InvalidEstablishment
     */
    public function revise(string $code, string $name, ?string $addressLine1, ?string $addressLine2, ?string $postalCode, ?string $city, ?string $phone, ?string $email, \DateTimeImmutable $now): bool
    {
        $next = [self::code($code), self::name($name), self::text($addressLine1), self::text($addressLine2), self::text($postalCode), self::text($city), self::text($phone), self::text($email)];
        $current = [$this->code, $this->name, $this->addressLine1, $this->addressLine2, $this->postalCode, $this->city, $this->phone, $this->email];
        if ($next === $current) {
            return false;
        }
        [$this->code, $this->name, $this->addressLine1, $this->addressLine2, $this->postalCode, $this->city, $this->phone, $this->email] = $next;
        $this->updatedAt = $now;

        return true;
    }

    /** @return bool whether this changed anything */
    public function markDefault(bool $isDefault, \DateTimeImmutable $now): bool
    {
        if ($this->isDefault === $isDefault) {
            return false;
        }
        $this->isDefault = $isDefault;
        $this->updatedAt = $now;

        return true;
    }

    private static function code(string $code): string
    {
        $code = trim($code);
        if (1 !== preg_match(self::CODE, $code)) {
            throw new InvalidEstablishment('code', \sprintf('"%s" is not an establishment code: 1 to 16 letters or digits.', $code));
        }

        return $code;
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidEstablishment('name', \sprintf('An establishment is named in 1 to %d characters.', self::NAME_MAX));
        }

        return $name;
    }

    private static function text(?string $value): ?string
    {
        $value = null === $value ? '' : trim($value);

        return '' === $value ? null : $value;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAddressLine1(): ?string
    {
        return $this->addressLine1;
    }

    public function getAddressLine2(): ?string
    {
        return $this->addressLine2;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }
}
