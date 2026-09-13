<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain;

use App\Tenancy\Domain\Company;
use BcMath\Number;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A tax a company charges, copied from its fiscal preset at creation and then the company's own to edit. Its code and
 * family are fixed once created, because documents will refer to them; its rate, amount and threshold may change,
 * and documents keep the snapshot they were issued with.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tax_component')]
#[ORM\UniqueConstraint(name: 'uniq_tax_component_company_code', columns: ['company_id', 'code'])]
class TaxComponent
{
    public const string CODE = '/^[A-Z][A-Z0-9_]{0,31}$/';
    /** A percentage from 0 to 100 that fits NUMERIC(6,3). */
    private const string RATE = '/^(0|[1-9][0-9]{0,2})(\.[0-9]{1,3})?$/';
    /** An amount that fits NUMERIC(14,3); the currency may allow fewer decimals. */
    private const string AMOUNT = '/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$/';
    private const int NAME_MAX = 120;
    private const int MENTION_MAX = 500;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    #[ORM\Column(length: 24, enumType: TaxKind::class)]
    private TaxKind $kind;

    #[ORM\Column(length: 16, enumType: TaxFamily::class)]
    private TaxFamily $family;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 3, nullable: true)]
    private ?string $rate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $amount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $threshold = null;

    #[ORM\Column]
    private bool $entersVatBase = false;

    #[ORM\Column]
    private bool $isDefault = false;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $exemptionMention = null;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Company $company, string $code, TaxFamily $family, \DateTimeImmutable $now)
    {
        if (1 !== preg_match(self::CODE, $code)) {
            throw new InvalidFiscalValue('code', \sprintf('"%s" is not a tax code: capital letters, digits and underscores, starting with a letter.', $code));
        }
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->code = $code;
        $this->family = $family;
        $this->kind = $family->kind();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** @param int $currencyScale the company currency's decimals, which an amount or a threshold may not exceed */
    public static function create(
        Company $company,
        string $code,
        string $name,
        TaxFamily $family,
        ?string $rate,
        ?string $amount,
        ?string $threshold,
        bool $entersVatBase,
        bool $isDefault,
        ?string $exemptionMention,
        int $sortOrder,
        int $currencyScale,
        \DateTimeImmutable $now,
    ): self {
        $component = new self($company, $code, $family, $now);
        $component->apply($name, $rate, $amount, $threshold, $entersVatBase, $isDefault, true, $exemptionMention, $sortOrder, $currencyScale);

        return $component;
    }

    /** @return bool whether anything changed */
    public function revise(
        string $name,
        ?string $rate,
        ?string $amount,
        ?string $threshold,
        bool $entersVatBase,
        bool $isDefault,
        bool $isActive,
        ?string $exemptionMention,
        int $sortOrder,
        int $currencyScale,
        \DateTimeImmutable $now,
    ): bool {
        $before = $this->state();
        $this->apply($name, $rate, $amount, $threshold, $entersVatBase, $isDefault, $isActive, $exemptionMention, $sortOrder, $currencyScale);
        if ($before === $this->state()) {
            return false;
        }
        $this->updatedAt = $now;

        return true;
    }

    private function apply(string $name, ?string $rate, ?string $amount, ?string $threshold, bool $entersVatBase, bool $isDefault, bool $isActive, ?string $exemptionMention, int $sortOrder, int $currencyScale): void
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidFiscalValue('name', \sprintf('A tax name has between 1 and %d characters.', self::NAME_MAX));
        }
        $rate = $this->decimal('rate', $rate, TaxKind::FixedDocument !== $this->kind, self::RATE, 3, max: 100);
        $amount = $this->decimal('amount', $amount, TaxKind::FixedDocument === $this->kind, self::AMOUNT, $currencyScale);
        $threshold = $this->decimal('threshold', $threshold, TaxKind::WithholdingTotal === $this->kind, self::AMOUNT, $currencyScale);
        if ($entersVatBase && !$this->family->mayEnterVatBase()) {
            throw new InvalidFiscalValue('entersVatBase', \sprintf('Only a levy enters the VAT base, not a %s tax.', $this->family->value));
        }
        $exemptionMention = null === $exemptionMention || '' === trim($exemptionMention) ? null : trim($exemptionMention);
        if (null !== $exemptionMention && mb_strlen($exemptionMention) > self::MENTION_MAX) {
            throw new InvalidFiscalValue('exemptionMention', \sprintf('An exemption mention has at most %d characters.', self::MENTION_MAX));
        }
        if ($sortOrder < 0) {
            throw new InvalidFiscalValue('sortOrder', 'A sort order is never negative.');
        }

        $this->name = $name;
        $this->rate = $rate;
        $this->amount = $amount;
        $this->threshold = $threshold;
        $this->entersVatBase = $entersVatBase;
        $this->isDefault = $isDefault;
        $this->isActive = $isActive;
        $this->exemptionMention = $exemptionMention;
        $this->sortOrder = $sortOrder;
    }

    /** The value written with its column's three decimals, as the database gives it back, or null when not applicable. */
    private function decimal(string $field, ?string $value, bool $required, string $shape, int $maxDecimals, ?int $max = null): ?string
    {
        $value = null === $value || '' === trim($value) ? null : trim($value);
        if (!$required) {
            if (null !== $value) {
                throw new InvalidFiscalValue($field, \sprintf('A %s tax has no %s.', $this->kind->value, $field));
            }

            return null;
        }
        if (null === $value || 1 !== preg_match($shape, $value) || !is_numeric($value)) {
            throw new InvalidFiscalValue($field, \sprintf('A %s tax needs a %s, a non-negative decimal with at most three decimals.', $this->kind->value, $field));
        }
        $number = new Number($value);
        if (null !== $max && $number->compare($max) > 0) {
            throw new InvalidFiscalValue($field, \sprintf('The %s is at most %d.', $field, $max));
        }
        if (0 !== $number->round($maxDecimals, \RoundingMode::HalfAwayFromZero)->compare($number)) {
            throw new InvalidFiscalValue($field, \sprintf('The %s may carry at most %d decimals, the currency\'s.', $field, $maxDecimals));
        }

        return (string) $number->add(0, 3);
    }

    /** @return list<mixed> every revisable value, to tell whether a revision changed anything */
    private function state(): array
    {
        return [$this->name, $this->rate, $this->amount, $this->threshold, $this->entersVatBase, $this->isDefault, $this->isActive, $this->exemptionMention, $this->sortOrder];
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

    public function getKind(): TaxKind
    {
        return $this->kind;
    }

    public function getFamily(): TaxFamily
    {
        return $this->family;
    }

    public function getRate(): ?string
    {
        return $this->rate;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function getThreshold(): ?string
    {
        return $this->threshold;
    }

    public function entersVatBase(): bool
    {
        return $this->entersVatBase;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getExemptionMention(): ?string
    {
        return $this->exemptionMention;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
