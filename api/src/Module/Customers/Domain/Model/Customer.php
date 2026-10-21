<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Domain\Model;

use App\Fiscal\Domain\Model\CustomerTaxRegime;
use App\Module\Customers\Domain\Exception\InvalidCustomer;
use App\Module\Customers\Domain\ValueObject\CustomerCheckReason;
use App\Module\Customers\Domain\ValueObject\CustomerCheckScope;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Shared\Domain\Model\PostalAddress;
use App\Shared\Domain\Service\CompanyOwned;
use App\Shared\Domain\ValueObject\CheckMark;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Someone a company sells to (docs/SPEC.md § 4 customer). Its number is the company's to choose and unique in it; its
 * tax regime comes from the company's own preset; its group, when it has one, is the company's too. Documents will name
 * a customer, so a customer they name is deactivated or merged into another, and only one nothing names is deleted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer')]
#[ORM\Index(name: 'idx_customer_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_customer_group', columns: ['customer_group_id'])]
#[ORM\Index(name: 'idx_customer_tax_regime', columns: ['tax_regime_id'])]
#[ORM\UniqueConstraint(name: 'uniq_customer_company_number', columns: ['company_id', 'number'])]
class Customer implements CompanyOwned
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

    #[ORM\Column(length: 16, enumType: CustomerKind::class)]
    private CustomerKind $kind;

    #[ORM\ManyToOne(targetEntity: CustomerGroup::class)]
    #[ORM\JoinColumn(name: 'customer_group_id', nullable: true)]
    private ?CustomerGroup $group = null;

    #[ORM\ManyToOne(targetEntity: CustomerTaxRegime::class)]
    #[ORM\JoinColumn(name: 'tax_regime_id', nullable: false)]
    private CustomerTaxRegime $taxRegime;

    #[ORM\Column(length: CustomerProfile::NAME_MAX)]
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

    #[ORM\Embedded(class: PostalAddress::class, columnPrefix: 'billing_')]
    private PostalAddress $billingAddress;

    #[ORM\Embedded(class: PostalAddress::class, columnPrefix: 'shipping_')]
    private PostalAddress $shippingAddress;

    /** @var list<string> the company's tax components a new line for this customer starts with */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $defaultTaxComponentIds = [];

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 3, nullable: true)]
    private ?string $defaultDiscountRate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /** @var array<string, string|int|float|bool> values by the company's custom field keys, checked by ManageCustomers */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $customFields = [];

    #[ORM\Column]
    private bool $isActive = true;

    /** « À vérifier » as a person set it, on the form or through a file's row. */
    #[ORM\Column(options: ['default' => false])]
    private bool $toCheck = false;

    /** What to look at, kept while the mark is on. */
    #[ORM\Column(length: CheckMark::NOTE_MAX, nullable: true)]
    private ?string $checkNote = null;

    /**
     * The customer this one was merged into, which lists, pickers and reports show in its place; written by the records
     * engine, which also switches it off, and emptied again by « Annuler la fusion ».
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'merged_into_id', nullable: true)]
    private ?Customer $mergedInto = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** Doctrine counts each save, so an editor of an older version is told rather than overwriting it. */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    private function __construct(Company $company, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @param list<Uuid> $defaultTaxComponentIds
     *
     * @throws InvalidCustomer
     */
    public static function create(Company $company, string $number, CustomerProfile $profile, ?CustomerGroup $group, CustomerTaxRegime $taxRegime, array $defaultTaxComponentIds, \DateTimeImmutable $now): self
    {
        $customer = new self($company, $now);
        $customer->number = self::number($number);
        $customer->apply($profile);
        $customer->group = $customer->groupOfThisCompany($group);
        $customer->taxRegime = $customer->regimeOfThisPreset($taxRegime);
        $customer->defaultTaxComponentIds = self::ids($defaultTaxComponentIds);

        return $customer;
    }

    /**
     * @param list<Uuid> $defaultTaxComponentIds
     *
     * @return list<string> the fields that changed, none when the revision says what the customer already says
     *
     * @throws InvalidCustomer
     */
    public function revise(string $number, CustomerProfile $profile, ?CustomerGroup $group, CustomerTaxRegime $taxRegime, array $defaultTaxComponentIds, bool $isActive, \DateTimeImmutable $now): array
    {
        $number = self::number($number);
        $group = $this->groupOfThisCompany($group);
        $taxRegime = $this->regimeOfThisPreset($taxRegime);
        $ids = self::ids($defaultTaxComponentIds);

        $changed = $number === $this->number ? [] : ['number'];
        $changed = [...$changed, ...$profile->differencesFrom($this->getProfile())];
        if ($group?->getId()->toRfc4122() !== $this->group?->getId()->toRfc4122()) {
            $changed[] = 'customerGroupId';
        }
        if (!$taxRegime->getId()->equals($this->taxRegime->getId())) {
            $changed[] = 'taxRegime';
        }
        if ($ids !== $this->defaultTaxComponentIds) {
            $changed[] = 'defaultTaxComponentIds';
        }
        if ($isActive !== $this->isActive) {
            $changed[] = 'isActive';
        }
        if ([] === $changed) {
            return [];
        }

        $this->number = $number;
        $this->apply($profile);
        $this->group = $group;
        $this->taxRegime = $taxRegime;
        $this->defaultTaxComponentIds = $ids;
        $this->isActive = $isActive;
        $this->updatedAt = $now;

        return $changed;
    }

    /**
     * The mark and its note as a person writes them.
     *
     * @return list<string> the fields that changed
     *
     * @throws InvalidCustomer
     */
    public function reviseCheck(CheckMark $mark, \DateTimeImmutable $now): array
    {
        $refusal = CheckMark::noteRefusal($mark->note);
        if (null !== $refusal) {
            throw new InvalidCustomer('checkNote', $refusal->message, $refusal->reason, $refusal->params);
        }
        $changed = [];
        if ($mark->marked !== $this->toCheck) {
            $changed[] = 'toCheck';
        }
        if ($mark->note !== $this->checkNote) {
            $changed[] = 'checkNote';
        }
        if ([] !== $changed) {
            $this->toCheck = $mark->marked;
            $this->checkNote = $mark->note;
            $this->updatedAt = $now;
        }

        return $changed;
    }

    public function getCheckMark(): CheckMark
    {
        return new CheckMark($this->toCheck, $this->checkNote);
    }

    /**
     * The numbers the scope requires that this customer lacks: none unless it is a business of the seller's country.
     *
     * @return list<string> identifier keys, in the preset's order
     */
    public function missingIdentifiers(CustomerCheckScope $scope): array
    {
        if (!$this->getProfile()->isDomesticBusiness($scope->countryCode)) {
            return [];
        }

        return array_values(array_filter($scope->requiredOfBusiness, fn (string $key): bool => !isset($this->identifiers[$key])));
    }

    /** @return list<CustomerCheckReason> what the customer lacks, in the order a screen lists it */
    public function checkReasons(CustomerCheckScope $scope): array
    {
        return [] === $this->missingIdentifiers($scope) ? [] : [CustomerCheckReason::IdentifierMissing];
    }

    /**
     * @param array<string, string|int|float|bool> $values
     *
     * @return list<string> the fields that changed, named `customFields.<key>`
     */
    public function reviseCustomFields(array $values, \DateTimeImmutable $now): array
    {
        $changed = [];
        foreach (array_unique([...array_keys($this->customFields), ...array_keys($values)]) as $key) {
            if (($this->customFields[$key] ?? null) !== ($values[$key] ?? null)) {
                $changed[] = 'customFields.'.$key;
            }
        }
        if ([] !== $changed) {
            $this->customFields = $values;
            $this->updatedAt = $now;
        }

        return $changed;
    }

    /** @return array<string, string|int|float|bool> */
    public function getCustomFields(): array
    {
        return $this->customFields;
    }

    public function getProfile(): CustomerProfile
    {
        return new CustomerProfile(
            $this->kind,
            $this->name,
            $this->legalName,
            $this->identifiers,
            $this->email,
            $this->phone,
            $this->website,
            $this->billingAddress,
            $this->shippingAddress,
            $this->defaultDiscountRate,
            $this->notes,
        );
    }

    private function apply(CustomerProfile $profile): void
    {
        // Held to the form's rules as it is written; read back from a stored row, it is not held again.
        $profile->check();
        $this->kind = $profile->kind;
        $this->name = $profile->name;
        $this->legalName = $profile->legalName;
        $this->identifiers = $profile->identifiers;
        $this->email = $profile->email;
        $this->phone = $profile->phone;
        $this->website = $profile->website;
        $this->billingAddress = $profile->billingAddress;
        $this->shippingAddress = $profile->shippingAddress ?? new PostalAddress();
        $this->defaultDiscountRate = $profile->defaultDiscountRate;
        $this->notes = $profile->notes;
    }

    private function groupOfThisCompany(?CustomerGroup $group): ?CustomerGroup
    {
        if (null !== $group && !$group->getCompany()->getId()->equals($this->company->getId())) {
            throw new InvalidCustomer('customerGroupId', 'A customer belongs to a group of its own company.', 'unknown_group_id');
        }

        return $group;
    }

    private function regimeOfThisPreset(CustomerTaxRegime $regime): CustomerTaxRegime
    {
        if ($regime->getFiscalPreset() !== $this->company->getFiscalPreset()) {
            throw new InvalidCustomer('taxRegime', \sprintf('The %s preset offers no regime "%s" of the %s preset.', $this->company->getFiscalPreset(), $regime->getCode(), $regime->getFiscalPreset()), 'unknown_tax_regime', ['code' => $regime->getCode()]);
        }

        return $regime;
    }

    private static function number(string $number): string
    {
        $number = trim($number);
        if (1 !== preg_match(self::NUMBER, $number)) {
            throw new InvalidCustomer('number', \sprintf('"%s" is not a customer number: 1 to 32 letters, digits, dots, dashes, slashes or underscores.', $number), 'invalid_number', ['max' => 32]);
        }

        return $number;
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids)));
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

    public function getGroup(): ?CustomerGroup
    {
        return $this->group;
    }

    public function getTaxRegime(): CustomerTaxRegime
    {
        return $this->taxRegime;
    }

    /** @return list<string> */
    public function getDefaultTaxComponentIds(): array
    {
        return $this->defaultTaxComponentIds;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getMergedInto(): ?self
    {
        return $this->mergedInto;
    }

    /** Merged into another: what was sent or issued still names it, nothing new does. */
    public function isMergedAway(): bool
    {
        return null !== $this->mergedInto;
    }

    /** Why a new document may not name it, switched off as it is: merged into another, or deactivated. */
    public function whySwitchedOff(): string
    {
        return null === $this->mergedInto
            ? \sprintf('The customer %s is deactivated.', $this->number)
            : \sprintf('The customer %s was merged into %s: a new document names the customer kept.', $this->number, $this->mergedInto->number);
    }

    /** The customer this one stands for: the customer kept when it was merged away, itself otherwise. */
    public function standingFor(): self
    {
        return $this->mergedInto ?? $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}
