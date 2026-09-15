<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Domain;

use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Someone a company buys from (docs/SPEC.md § 4 vendor). Its number is the company's to choose and unique in it.
 * Expenses will name a vendor, so a vendor is deactivated, never deleted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vendor')]
#[ORM\Index(name: 'idx_vendor_company', columns: ['company_id'])]
#[ORM\UniqueConstraint(name: 'uniq_vendor_company_number', columns: ['company_id', 'number'])]
class Vendor
{
    public const string NUMBER = '/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,31}$/';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 32)]
    private string $number;

    #[ORM\Column(length: VendorProfile::NAME_MAX)]
    private string $name;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $legalName = null;

    /** @var array<string, string> registration numbers by the preset's identifier key */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $identifiers = [];

    #[ORM\Column(length: 254, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $website = null;

    #[ORM\Embedded(class: PostalAddress::class, columnPrefix: 'address_')]
    private PostalAddress $address;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban = null;

    #[ORM\Column(length: 11, nullable: true)]
    private ?string $bic = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $paymentTermsDays = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /** An id rather than an association: vendors work without the expenses module, which depends on them. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $defaultExpenseCategoryId = null;

    #[ORM\Column]
    private bool $isActive = true;

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

    /** @throws InvalidVendor */
    public static function create(Company $company, string $number, VendorProfile $profile, \DateTimeImmutable $now): self
    {
        $vendor = new self($company, $now);
        $vendor->number = self::number($number);
        $vendor->apply($profile);

        return $vendor;
    }

    /**
     * @return list<string> the fields that changed, none when the revision says what the vendor already says
     *
     * @throws InvalidVendor
     */
    public function revise(string $number, VendorProfile $profile, bool $isActive, \DateTimeImmutable $now): array
    {
        $number = self::number($number);

        $changed = $number === $this->number ? [] : ['number'];
        $changed = [...$changed, ...$profile->differencesFrom($this->getProfile())];
        if ($isActive !== $this->isActive) {
            $changed[] = 'isActive';
        }
        if ([] === $changed) {
            return [];
        }

        $this->number = $number;
        $this->apply($profile);
        $this->isActive = $isActive;
        $this->updatedAt = $now;

        return $changed;
    }

    public function getProfile(): VendorProfile
    {
        return new VendorProfile(
            $this->name,
            $this->legalName,
            $this->identifiers,
            $this->email,
            $this->phone,
            $this->website,
            $this->address,
            $this->iban,
            $this->bic,
            $this->paymentTermsDays,
            $this->notes,
            $this->defaultExpenseCategoryId,
        );
    }

    private function apply(VendorProfile $profile): void
    {
        $this->name = $profile->name;
        $this->legalName = $profile->legalName;
        $this->identifiers = $profile->identifiers;
        $this->email = $profile->email;
        $this->phone = $profile->phone;
        $this->website = $profile->website;
        $this->address = $profile->address;
        $this->iban = $profile->iban;
        $this->bic = $profile->bic;
        $this->paymentTermsDays = $profile->paymentTermsDays;
        $this->notes = $profile->notes;
        $this->defaultExpenseCategoryId = $profile->defaultExpenseCategoryId;
    }

    private static function number(string $number): string
    {
        $number = trim($number);
        if (1 !== preg_match(self::NUMBER, $number)) {
            throw new InvalidVendor('number', \sprintf('"%s" is not a vendor number: 1 to 32 letters, digits, dots, dashes, slashes or underscores.', $number));
        }

        return $number;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
